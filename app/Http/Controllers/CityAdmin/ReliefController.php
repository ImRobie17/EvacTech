<?php

namespace App\Http\Controllers\CityAdmin;

use App\Http\Controllers\Controller;
use App\Models\EvacuationCenter;
use App\Models\ReliefAllocationRequest;
use App\Models\ReliefInventory;
use App\Models\ReliefTransaction;
use App\Models\SpecialReliefRequest;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReliefController extends Controller
{
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
            ];
        });

        // Approval queue 1: shelter restock requests (city warehouse -> shelter).
        $restockRequests = ReliefAllocationRequest::with(['evacuationCenter', 'reliefGood', 'requestedBy'])
            ->where('status', 'pending')
            ->latest('requested_at')
            ->get();

        // Approval queue 2: per-household special item requests.
        $specialRequests = SpecialReliefRequest::with(['household.headMember', 'evacuationCenter', 'requestedBy'])
            ->where('status', 'pending')
            ->latest()
            ->get();

        return view('cityadmin.relief.index', compact('shelters', 'restockRequests', 'specialRequests'));
    }

    public function approveRestock(Request $request, ReliefAllocationRequest $reliefRequest)
    {
        abort_if($reliefRequest->status !== 'pending', 422, 'This request has already been resolved.');
        $decision = $request->validate(['decision' => ['required', 'in:approve,reject']])['decision'];

        if ($decision === 'reject') {
            $reliefRequest->update([
                'status' => 'rejected', 'approved_by' => auth()->id(), 'resolved_at' => now(),
            ]);
            AuditLogger::log('updated', $reliefRequest, 'Rejected restock request');
            return back()->with('success', 'Restock request rejected.');
        }

        // Approve: add the requested quantity to the shelter's inventory and log it.
        DB::transaction(function () use ($reliefRequest) {
            $inv = ReliefInventory::firstOrCreate(
                ['evacuation_center_id' => $reliefRequest->evacuation_center_id, 'relief_good_id' => $reliefRequest->relief_good_id],
                ['quantity_on_hand' => 0, 'reorder_level' => 0]
            );
            $inv->increment('quantity_on_hand', $reliefRequest->requested_quantity);
            $inv->update(['last_updated_at' => now()]);

            ReliefTransaction::create([
                'evacuation_center_id' => $reliefRequest->evacuation_center_id,
                'relief_good_id' => $reliefRequest->relief_good_id,
                'type' => 'allocated_in',
                'quantity' => $reliefRequest->requested_quantity,
                'source_or_recipient' => 'City warehouse (approved allocation)',
                'recorded_by' => auth()->id(),
                'transaction_date' => now()->toDateString(),
            ]);

            $reliefRequest->update([
                'status' => 'approved', 'approved_by' => auth()->id(), 'resolved_at' => now(),
            ]);
        });

        AuditLogger::log('updated', $reliefRequest, 'Approved restock request');
        return back()->with('success', 'Restock approved and inventory updated.');
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
