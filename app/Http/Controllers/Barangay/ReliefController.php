<?php

namespace App\Http\Controllers\Barangay;

use App\Models\Household;
use App\Models\ReliefGood;
use App\Models\ReliefInventory;
use App\Models\ReliefTransaction;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ReliefController extends BarangayController
{
    public function index(Request $request, ?\App\Models\EvacuationCenter $center = null)
    {
        $center = $this->center($center);

        $stats = ['received' => 0, 'distributed' => 0, 'remaining' => 0, 'days_left' => null];
        $log = collect();
        $priority = collect();
        $inventory = collect();
        $goods = ReliefGood::orderBy('name')->get();

        if ($center) {
            $stats['received'] = ReliefTransaction::where('evacuation_center_id', $center->id)
                ->whereIn('type', ['received', 'allocated_in'])->sum('quantity');
            $stats['distributed'] = ReliefTransaction::where('evacuation_center_id', $center->id)
                ->where('type', 'distributed')->sum('quantity');
            $stats['remaining'] = ReliefInventory::where('evacuation_center_id', $center->id)->sum('quantity_on_hand');

            // Projection: 7-day rolling average of distribution rate. An estimate, not a promise.
            $last7 = ReliefTransaction::where('evacuation_center_id', $center->id)
                ->where('type', 'distributed')
                ->where('transaction_date', '>=', Carbon::today()->subDays(6))
                ->sum('quantity');
            $dailyRate = $last7 / 7;
            $stats['days_left'] = $dailyRate > 0 ? (int) floor($stats['remaining'] / $dailyRate) : null;

            $logQuery = ReliefTransaction::with(['household.headMember', 'reliefGood', 'recordedBy'])
                ->where('evacuation_center_id', $center->id)
                ->where('type', 'distributed');

            if ($search = trim((string) $request->input('q'))) {
                $logQuery->whereHas('household.members', fn ($q) => $q
                    ->where('is_household_head', true)
                    ->where('full_name', 'like', "%{$search}%"));
            }

            $log = $logQuery->latest('created_at')->paginate(15)->withQueryString();

            // Priority: checked-in households with no distribution since $since (default 3 days ago)
            $since = $request->date('since') ?? Carbon::today()->subDays(3);
            $priority = Household::with('headMember')
                ->where('evacuation_center_id', $center->id)
                ->where('status', 'checked_in')
                ->whereDoesntHave('reliefTransactions', fn ($q) => $q
                    ->where('evacuation_center_id', $center->id)
                    ->where('type', 'distributed')
                    ->where('transaction_date', '>=', $since->toDateString()))
                ->get()
                ->map(function ($h) use ($center) {
                    $last = ReliefTransaction::where('household_id', $h->id)
                        ->where('evacuation_center_id', $center->id)
                        ->where('type', 'distributed')
                        ->latest('transaction_date')->first();
                    $h->last_received = $last?->transaction_date;
                    return $h;
                })
                ->sortBy(fn ($h) => $h->last_received ?? Carbon::minValue());

            $inventory = ReliefInventory::with('reliefGood')
                ->where('evacuation_center_id', $center->id)
                ->get();
        }

        return view('barangay.relief.index', array_merge(
            compact('center', 'stats', 'log', 'priority', 'inventory', 'goods'),
            $this->cityChrome($center)
        ));
    }

    /** Distribute relief to a household. Auto-decrements inventory (PB-09). */
    public function distribute(Request $request)
    {
        $center = $this->centerOrFail();

        $data = $request->validate([
            'household_id' => ['required', 'exists:households,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.relief_good_id' => ['required', 'exists:relief_goods,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'remarks' => ['nullable', 'string', 'max:500'], // free-text special requests (diapers, meds, wheelchair...)
        ]);

        $household = Household::findOrFail($data['household_id']);
        abort_if($household->origin_barangay_id !== auth()->user()->barangay_id, 403);

        DB::transaction(function () use ($data, $center, $household) {
            foreach ($data['items'] as $item) {
                $inventory = ReliefInventory::firstOrCreate(
                    ['evacuation_center_id' => $center->id, 'relief_good_id' => $item['relief_good_id']],
                    ['quantity_on_hand' => 0, 'reorder_level' => 0]
                );

                if ($inventory->quantity_on_hand < $item['quantity']) {
                    abort(422, 'Not enough stock of ' . $inventory->reliefGood->name . " (on hand: {$inventory->quantity_on_hand}).");
                }

                $inventory->decrement('quantity_on_hand', $item['quantity']);
                $inventory->update(['last_updated_at' => now()]);

                ReliefTransaction::create([
                    'evacuation_center_id' => $center->id,
                    'relief_good_id' => $item['relief_good_id'],
                    'type' => 'distributed',
                    'quantity' => $item['quantity'],
                    'household_id' => $household->id,
                    'recorded_by' => auth()->id(),
                    'transaction_date' => now()->toDateString(),
                    'remarks' => $data['remarks'] ?? null,
                ]);
            }
        });

        AuditLogger::log('created', $household, "Distributed relief to {$household->household_code}");

        return redirect()->route('barangay.relief.index')->with('success', 'Relief distribution logged.');
    }

    /** Record stock received at the center (delivery from city / donations). */
    public function receive(Request $request)
    {
        $center = $this->centerOrFail();

        $data = $request->validate([
            'relief_good_id' => ['required', 'exists:relief_goods,id'],
            'quantity' => ['required', 'integer', 'min:1'],
            'source' => ['nullable', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($data, $center) {
            $inventory = ReliefInventory::firstOrCreate(
                ['evacuation_center_id' => $center->id, 'relief_good_id' => $data['relief_good_id']],
                ['quantity_on_hand' => 0, 'reorder_level' => 0]
            );
            $inventory->increment('quantity_on_hand', $data['quantity']);
            $inventory->update(['last_updated_at' => now()]);

            ReliefTransaction::create([
                'evacuation_center_id' => $center->id,
                'relief_good_id' => $data['relief_good_id'],
                'type' => 'received',
                'quantity' => $data['quantity'],
                'source_or_recipient' => $data['source'] ?? null,
                'recorded_by' => auth()->id(),
                'transaction_date' => now()->toDateString(),
            ]);
        });

        return back()->with('success', 'Stock received and inventory updated.');
    }

    /** Relief history for one household (search function in the spec). */
    public function history(Household $household)
    {
        abort_if($household->origin_barangay_id !== auth()->user()->barangay_id, 403);

        $rows = ReliefTransaction::with('reliefGood', 'recordedBy')
            ->where('household_id', $household->id)
            ->where('type', 'distributed')
            ->latest('transaction_date')
            ->get()
            ->map(fn ($t) => [
                'date' => $t->transaction_date->format('M d, Y'),
                'good' => $t->reliefGood->name,
                'quantity' => $t->quantity,
                'unit' => $t->reliefGood->unit,
                'by' => $t->recordedBy?->name,
                'remarks' => $t->remarks,
            ]);

        return response()->json($rows);
    }

        public function requestRestock(Request $request)
    {
        $center = $this->centerOrFail();

        $data = $request->validate([
            'relief_good_id' => ['required', 'exists:relief_goods,id'],
            'requested_quantity' => ['required', 'integer', 'min:1'],
        ]);

        $reliefRequest = \App\Models\ReliefAllocationRequest::create([
            'evacuation_center_id' => $center->id,
            'relief_good_id' => $data['relief_good_id'],
            'requested_quantity' => $data['requested_quantity'],
            'status' => 'pending',
            'requested_by' => auth()->id(),
            'requested_at' => now(),
        ]);

        \App\Services\AuditLogger::log('created', $reliefRequest, 'Requested relief restock from city');

        return back()->with('success', 'Restock request submitted for City Admin approval.');
    }
    
    public function requestSpecial(Request $request)
    {
        $center = $this->centerOrFail();

        $data = $request->validate([
            'household_id' => ['required', 'exists:households,id'],
            'item_description' => ['required', 'string', 'max:255'],
            'quantity' => ['required', 'integer', 'min:1'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);

        $household = \App\Models\Household::findOrFail($data['household_id']);
        abort_if($household->origin_barangay_id !== auth()->user()->barangay_id, 403);

        $special = \App\Models\SpecialReliefRequest::create([
            'household_id' => $household->id,
            'evacuation_center_id' => $center->id,
            'item_description' => $data['item_description'],
            'quantity' => $data['quantity'],
            'status' => 'pending',
            'requested_by' => auth()->id(),
            'remarks' => $data['remarks'] ?? null,
        ]);

        \App\Services\AuditLogger::log('created', $special, "Requested special item: {$special->item_description}");

        return back()->with('success', 'Special item request submitted for City Admin approval.');
    }
}
