<?php

namespace App\Http\Controllers\CityAdmin;

use App\Http\Controllers\Concerns\RecordsReliefReceipt;
use App\Http\Controllers\Controller;
use App\Models\EvacuationCenter;
use App\Models\ReliefAllocationRequest;
use App\Models\ReliefInventory;
use App\Models\ReliefGood;
use Illuminate\Validation\ValidationException;
use App\Models\SpecialReliefRequest;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReliefController extends Controller
{
    /* City Admin may record optional donor details during approval, but
       stock-in and the donated-value ledger row are deferred until the
       requesting shelter confirms physical receipt. */
    use RecordsReliefReceipt;

    public function index()
    {
        // Per-shelter stock status: totals + low-stock flag per center.
        $shelters = EvacuationCenter::with('barangay')->orderBy('name')->get()->map(function ($c) {
            $onHand = ReliefInventory::where('evacuation_center_id', $c->id)->sum('quantity_on_hand');
            $low = ReliefInventory::where('evacuation_center_id', $c->id)
                ->whereColumn('quantity_on_hand', '<=', 'reorder_level')
                ->where('reorder_level', '>', 0)->count();
            return [
                'id' => $c->id,
                'name' => $c->name,
                'barangay' => $c->barangay?->name,
                'status' => $c->status,
                'on_hand' => $onHand,
                'low_items' => $low,
                // DROP B1. Peso value of everything that ARRIVED at this
                // shelter. Sums stock-in types only -- see the trait.
                'value_received' => $this->reliefValueReceived($c),
            ];
        });

        // City-wide donated value. Computed from the transactions rather than
        // by adding up the column above, so it stays right if the shelter list
        // is ever filtered or paginated.
        $cityValueReceived = $this->reliefValueReceived();

        // Controllers prepare, views render.
        $donorTypes = \App\Models\ReliefTransaction::DONOR_TYPES;

        // Approval queue 1: shelter restock requests (city warehouse -> shelter).
        $restockRequests = ReliefAllocationRequest::with(['evacuationCenter', 'reliefGood', 'requestedBy'])
            ->where('status', 'pending')
            ->latest('requested_at')
            ->get();

        // Approval queue 2: per-household special item requests.
        $awaitingDeliveries = ReliefAllocationRequest::with(['evacuationCenter', 'reliefGood', 'latestReceipt'])
            ->where('status', 'approved')
            ->whereNull('delivery_closed_at')
            ->whereColumn('received_quantity', '<', 'requested_quantity')
            ->latest('resolved_at')->get();

        $goods = ReliefGood::orderBy('name')->get();
        $specialRequests = SpecialReliefRequest::with(['household.headMember', 'evacuationCenter', 'requestedBy'])
            ->where('status', 'pending')
            ->latest()
            ->get();

        return view('cityadmin.relief.index', compact(
            'shelters', 'restockRequests', 'specialRequests', 'cityValueReceived', 'donorTypes', 'goods', 'awaitingDeliveries'
        ));
    }

    /** Approval authorizes delivery. The camp manager credits stock upon arrival. */
    public function approveRestock(Request $request, ReliefAllocationRequest $reliefRequest)
    {
        $data = $request->validate(array_merge([
            'decision' => ['required', 'in:approve,reject'],
            'catalog_choice' => ['nullable', 'in:existing,new'],
            'matched_good_id' => ['nullable', 'integer', 'exists:relief_goods,id'],
            'new_good_unit' => ['nullable', 'string', 'max:20'],
        ], $this->reliefDonorRules(false)));

        $result = DB::transaction(function () use ($reliefRequest, $data) {
            $line = ReliefAllocationRequest::whereKey($reliefRequest->id)->lockForUpdate()->firstOrFail();
            abort_unless($line->status === 'pending', 422, 'This request has already been reviewed.');

            if ($data['decision'] === 'reject') {
                $line->update([
                    'status' => 'rejected', 'approved_by' => auth()->id(), 'resolved_at' => now(),
                ]);
                AuditLogger::log('updated', $line, 'Rejected restock request');
                return 'rejected';
            }

            if ($line->relief_good_id === null) {
                if (($data['catalog_choice'] ?? null) === 'existing') {
                    if (empty($data['matched_good_id'])) {
                        throw ValidationException::withMessages([
                            'matched_good_id' => 'Select the existing relief good for this request.',
                        ]);
                    }
                    $line->relief_good_id = $data['matched_good_id'];
                } elseif (($data['catalog_choice'] ?? null) === 'new') {
                    $unit = trim((string) ($data['new_good_unit'] ?? ''));
                    if ($unit === '') {
                        throw ValidationException::withMessages([
                            'new_good_unit' => 'Enter the unit for the new relief good.',
                        ]);
                    }
                    $name = trim($line->custom_good_name);
                    // A matching name must not silently create a second catalogue item.
                    // Reuse it and its established unit; other pack sizes should
                    // have an explicitly different catalogue name.
                    $good = ReliefGood::where('name', $name)->lockForUpdate()->first();
                    if (! $good) {
                        $good = ReliefGood::create([
                            'name' => $name, 'unit' => $unit,
                            'category' => 'other', 'is_monetary' => false,
                        ]);
                    } elseif (strcasecmp($good->unit, $unit) !== 0) {
                        throw ValidationException::withMessages([
                            'new_good_unit' => 'An item with this name already exists with unit ' . $good->unit . '. Match that item or use a distinct name.',
                        ]);
                    }
                    $line->relief_good_id = $good->id;
                } else {
                    throw ValidationException::withMessages([
                        'catalog_choice' => 'Choose how to resolve this custom relief item.',
                    ]);
                }
            }

            $line->status = 'approved';
            $line->approved_by = auth()->id();
            $line->resolved_at = now();
            $line->donor_type = $data['donor_type'] ?? null;
            $line->donor_name = $data['donor_name'] ?? null;
            $line->monetary_value = $data['monetary_value'] ?? null;
            $line->save();
            AuditLogger::log('updated', $line, 'Approved restock request; awaiting shelter arrival');
            return 'approved';
        });

        return back()->with('success', 'Restock request ' . $result .
            ($result === 'approved' ? '. Shelter inventory will update only after arrival confirmation.' : '.'));
    }

    /** Record a shortage/cancel the remaining delivery without crediting stock. */
    public function closeOutstandingDelivery(Request $request, ReliefAllocationRequest $reliefRequest)
    {
        $data = $request->validate([
            'delivery_closure_reason' => ['required', 'string', 'max:500'],
        ]);
        DB::transaction(function () use ($reliefRequest, $data) {
            $line = ReliefAllocationRequest::whereKey($reliefRequest->id)->lockForUpdate()->firstOrFail();
            abort_unless($line->status === 'approved' && $line->delivery_closed_at === null
                && $line->remaining_quantity > 0, 422, 'This delivery is already resolved.');
            $line->update([
                'delivery_closed_at' => now(), 'delivery_closed_by' => auth()->id(),
                'delivery_closure_reason' => trim($data['delivery_closure_reason']),
            ]);
            AuditLogger::log('updated', $line, 'Closed undelivered relief balance');
        });
        return back()->with('success', 'Outstanding delivery closed. No additional stock was added.');
    }

    public function reviewSpecial(Request $request, SpecialReliefRequest $specialRequest)
    {
        abort_if($specialRequest->status !== 'pending', 422, 'This request has already been resolved.');
        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);

        $specialRequest->update([
            'status' => $data['decision'] === 'approve' ? 'approved' : 'rejected',
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
            'remarks' => $data['remarks'] ?? $specialRequest->remarks,
        ]);

        AuditLogger::log('updated', $specialRequest, ucfirst($data['decision']) . "d special relief request: {$specialRequest->item_description}");

        return back()->with('success', 'Special request ' . $specialRequest->status . '.');
    }
}
