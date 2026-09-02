<?php

namespace App\Http\Controllers\CityAdmin;

use App\Http\Controllers\Concerns\RecordsReliefReceipt;
use App\Http\Controllers\Controller;
use App\Models\EvacuationCenter;
use App\Models\ReliefAllocationRequest;
use App\Models\ReliefInventory;
use App\Models\SpecialReliefRequest;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReliefController extends Controller
{
    /* DROP B1. approveRestock() is the THIRD stock-in path. It now captures a
       donor too, so it shares the donor rules and the write with the two direct
       receive paths rather than keeping a third private copy of them. */
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
        $specialRequests = SpecialReliefRequest::with(['household.headMember', 'evacuationCenter', 'requestedBy'])
            ->where('status', 'pending')
            ->latest()
            ->get();

        return view('cityadmin.relief.index', compact(
            'shelters', 'restockRequests', 'specialRequests', 'cityValueReceived', 'donorTypes'
        ));
    }

    /**
     * Approve or reject a shelter's restock request.
     *
     * DROP B1 -- THIS PATH NOW CARRIES A DONOR, and that is a deliberate change
     * of meaning.
     *
     * It used to be treated as purely internal: city stock moving to a shelter,
     * never a donation, explicitly excluded from any donated-value total. In
     * practice a donation does not always reach a shelter directly -- it often
     * arrives at the CSWD Office first and is allocated onward. Recording the
     * donor only on the direct receive path lost every one of those from the
     * client's monetary tracking.
     *
     * So donor_type and monetary_value are OPTIONAL here, unlike on the two
     * direct receive paths. Left blank, the row is exactly what it always was:
     * city stock, no donor, no value, contributing nothing to the totals.
     * Filled in, it is a donation that happened to be routed through the city.
     *
     * THE RISK, and it is stated on the form too: if the CSWD Office records a
     * value here AND the camp manager records a Receive for the same goods when
     * they physically arrive, that donation is counted twice. Nothing in the
     * schema can tell those two rows apart, so this is a procedural rule, not
     * an enforced one.
     */
    public function approveRestock(Request $request, ReliefAllocationRequest $reliefRequest)
    {
        abort_if($reliefRequest->status !== 'pending', 422, 'This request has already been resolved.');

        $data = $request->validate(array_merge(
            ['decision' => ['required', 'in:approve,reject']],
            // false: no donor is the normal case here.
            $this->reliefDonorRules(false)
        ));
        $decision = $data['decision'];

        if ($decision === 'reject') {
            $reliefRequest->update([
                'status' => 'rejected', 'approved_by' => auth()->id(), 'resolved_at' => now(),
            ]);
            AuditLogger::log('updated', $reliefRequest, 'Rejected restock request');
            return back()->with('success', 'Restock request rejected.');
        }

        // Approve: add the requested quantity to the shelter's inventory and log it.
        DB::transaction(function () use ($reliefRequest, $data) {
            /* Routed through the shared writer rather than a fourth hand-rolled
               copy of "increment inventory, write a ledger row". The quantity
               comes from the REQUEST, not from the form -- the CSWD Office
               approves what was asked for; it does not retype it. */
            $this->applyReliefReceipt(
                $reliefRequest->evacuationCenter,
                $reliefRequest->reliefGood,
                [
                    'quantity' => $reliefRequest->requested_quantity,
                    'donor_type' => $data['donor_type'] ?? null,
                    'donor_name' => $data['donor_name'] ?? null,
                    'monetary_value' => $data['monetary_value'] ?? null,
                    'remarks' => null,
                ],
                'allocated_in',
                'City warehouse (approved allocation)'
            );

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
