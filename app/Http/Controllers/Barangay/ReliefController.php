<?php

namespace App\Http\Controllers\Barangay;

use App\Models\EvacuationCenter;
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
    public function index(Request $request, ?EvacuationCenter $center = null)
    {
        $center = $this->center($center);

        $stats = ['received' => 0, 'distributed' => 0, 'remaining' => 0, 'days_left' => null];
        $log = collect();
        $priority = collect();
        $inventory = collect();
        $goods = ReliefGood::orderBy('name')->get();

        // Initialised outside the $center branch, like the collections above,
        // because compact() at the end of this method runs either way.
        $restockRequests = collect();
        $specialRequests = collect();

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
                ->sortBy(fn ($h) => $h->last_received ?? \Illuminate\Support\Carbon::createFromTimestamp(0));

            $inventory = ReliefInventory::with('reliefGood')
                ->where('evacuation_center_id', $center->id)
                ->get();

            // ---- Request queues, this shelter only ----
            // Pending PLUS anything resolved in the last week, deliberately. A
            // request that disappears the moment it is answered is the same
            // silent channel the missing Request button already produced: staff
            // submit, see nothing, and assume it failed. Keeping resolved
            // requests visible for a few days is what makes an approval or a
            // rejection land with the person who asked.
            $restockRequests = \App\Models\ReliefAllocationRequest::with(['reliefGood', 'requestedBy'])
                ->where('evacuation_center_id', $center->id)
                ->where(fn ($q) => $q
                    ->where('status', 'pending')
                    ->orWhere('resolved_at', '>=', Carbon::now()->subDays(7)))
                ->latest('requested_at')
                ->get();

            $specialRequests = \App\Models\SpecialReliefRequest::with(['household.headMember', 'requestedBy'])
                ->where('evacuation_center_id', $center->id)
                ->where(fn ($q) => $q
                    ->where('status', 'pending')
                    ->orWhere('reviewed_at', '>=', Carbon::now()->subDays(7)))
                ->latest()
                ->get();
        }

        return view('barangay.relief.index', compact(
            'center', 'stats', 'log', 'priority', 'inventory', 'goods',
            'restockRequests', 'specialRequests'
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
        // Shelter-based authorisation (was: origin_barangay_id vs user barangay_id).
        $this->authorizeHousehold($household);

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

    // PHASE 4 item 15: history() deleted, along with the barangay.relief.history
    // route that was its only way in. It returned one household's distribution
    // history as JSON for a lookup that index() now answers directly -- the
    // relief page has a server-rendered, searchable history panel, so nothing
    // ever called the endpoint.

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
        $this->authorizeHousehold($household);

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

    /**
     * PHASE 8 ITEM 2 -- households this shelter may hand relief to.
     *
     * WHY THIS EXISTS RATHER THAN REUSING evacuees.search.
     *
     * Both relief pickers on this screen -- Distribute Relief and the special
     * item request -- used to point at Barangay\EvacueeProfilingController::
     * search(), which returns every household on the staff member's roster PLUS
     * every unassigned one, regardless of status. That was tolerable while the
     * list only appeared after someone typed a name they already had in mind.
     * It stops being tolerable the moment the list is PREFILLED, because then
     * the default state of the screen becomes a roster of families the operator
     * cannot actually give anything to: checked out, never checked in, or
     * sitting in a different shelter entirely.
     *
     * So this mirrors CityAdmin\ShelterDetailController::searchReliefRecipients()
     * exactly -- checked in, at THIS shelter -- and the two roles now answer the
     * question the same way. The scoping applies to the typed search as well as
     * the prefill, which is the point: a family who is not here should not be
     * offerable at all, not merely absent from the default list.
     *
     * `members_present`, not `number_of_members`: relief is issued against who
     * is actually at the shelter, which is the same figure occupancy is derived
     * from.
     *
     * NOTE ON THE SERVER RULE. distribute() and requestSpecial() still validate
     * `exists:households,id` plus authorizeHousehold(), and neither requires the
     * household to be checked in here. This narrows what the interface OFFERS;
     * it does not add a new server-side constraint. Tightening those two is
     * deliberately left alone -- check-in flexibility is Phase 9, and a rule
     * added here would be a rule added in the middle of a screen that is about
     * to change. Recorded in the phase notes rather than fixed in passing.
     */
    public function searchRecipients(Request $request)
    {
        $center = $this->centerOrFail();
        $term = trim((string) $request->input('q'));

        $results = Household::with('headMember')
            ->where('evacuation_center_id', $center->id)
            ->where('status', 'checked_in')
            // when(), not a required filter: a blank term is a legitimate query
            // meaning "everyone here", and it is what the prefill sends.
            ->when($term, fn ($q) => $q->whereHas('members', fn ($m) => $m
                ->where('is_household_head', true)
                ->where('full_name', 'like', "%{$term}%")))
            ->orderByDesc('checked_in_at')
            ->limit(10)
            ->get()
            ->map(fn ($h) => [
                'id' => $h->id,
                'code' => $h->household_code,
                'head' => $h->headMember?->full_name ?? '-',
                'size' => $h->members_present,
            ]);

        return response()->json($results);
    }
}
