<?php

namespace App\Http\Controllers\Barangay;

use App\Http\Controllers\Concerns\DistributesRelief;
use App\Http\Controllers\Concerns\RecordsReliefReceipt;
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
    // DROP B1. Rules and the stock-in write are shared with the two CSWD
    // Office paths -- see the trait for why all three had to move into one
    // place.
    use RecordsReliefReceipt;

    /* DROP B2. Distribution rules and the write, shared with
       CityAdmin\ShelterDetailController::distributeRelief(). Those two had
       already drifted apart once on shelter scoping; they cannot now. */
    use DistributesRelief;

    public function index(Request $request, ?EvacuationCenter $center = null)
    {
        $center = $this->center($center);

        $stats = [
            'received' => 0, 'distributed' => 0, 'remaining' => 0,
            'days_left' => null, 'value_received' => 0.0,
        ];
        $log = collect();
        $priority = collect();
        $inventory = collect();
        $goods = ReliefGood::orderBy('name')->get();

        // Prepared here rather than read from the model in Blade -- controllers
        // prepare, views render. Same reason reportFilterOptions() is passed in
        // rather than the view calling VulnerableClassification directly.
        $donorTypes = ReliefTransaction::DONOR_TYPES;

        // DROP B1. Stock-in rows for the new Stock Receipts panel. Before this
        // drop NOTHING on any screen rendered a `received` transaction -- both
        // relief pages showed only the Distribution Log -- so donor, value and
        // remarks would have been write-only fields the client could never see.
        $receipts = collect();
        $receiptCount = 0;

        // Initialised outside the $center branch, like the collections above,
        // because compact() at the end of this method runs either way.
        $restockRequests = collect();
        $specialRequests = collect();

        if ($center) {
            /* DROP B1. The four unit figures and the peso total now come from
               RecordsReliefReceipt::reliefStockStats(), which excludes MONETARY
               goods from every unit count. They used to be written out longhand
               here and again, identically, in CityAdmin\ShelterDetailController
               ::reliefData() -- two copies of one rule, which is how the two
               distribute paths came to disagree about shelter scoping for a
               whole phase.

               The exclusion matters: Financial Assistance stores pesos in
               `quantity` by the client's decision, so counting it here would
               make "Received" read 5,200 where 200 packs arrived, and would
               make the days-of-stock projection divide pesos by packs. */
            $stats = $this->reliefStockStats($center);

            $receipts = $this->reliefReceipts($center);
            $receiptCount = $this->reliefReceiptCount($center);

            $logQuery = ReliefTransaction::with(['household.headMember', 'reliefGood', 'recordedBy'])
                ->where('evacuation_center_id', $center->id)
                ->where('type', 'distributed');

            if ($search = trim((string) $request->input('q'))) {
                // PHASE 9 ITEM 1. Any member, not only the head. Rooted on
                // ReliefTransaction, hence the household.members path.
                $logQuery->whereHas('household.members', fn ($q) => $q->nameMatches($search));
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
            'restockRequests', 'specialRequests', 'receipts', 'receiptCount', 'donorTypes'
        ));
    }

    /** Distribute relief to a household. Auto-decrements inventory (PB-09). */
    /**
     * Log relief handed to one household.
     *
     * DROP B2. Four things changed, and all four are shared with
     * CityAdmin\ShelterDetailController::distributeRelief() through the
     * DistributesRelief trait. CHANGE THEM TOGETHER OR NOT AT ALL.
     *
     *   1. Duplicate rows of the same item are aggregated before the stock
     *      check, so "Rice 5" twice against a stock of 8 reports one honest
     *      shortage instead of half-succeeding and then quoting a number the
     *      operator never saw.
     *   2. A shortage is a ValidationException, not abort(422), so the operator
     *      gets the form back with their household, item rows and remarks
     *      intact rather than a bare error page.
     *   3. Quantity is bounded above as well as below.
     *   4. THE BEHAVIOUR CHANGE: the household must be checked in AT THIS
     *      SHELTER. authorizeHousehold() alone never proved that, because
     *      canManageHousehold() returns true for a household with a null
     *      evacuation_center_id. See assertHouseholdIsHere().
     */
    public function distribute(Request $request)
    {
        $center = $this->centerOrFail();

        $data = $request->validate($this->reliefDistributionRules());

        $household = Household::findOrFail($data['household_id']);
        // Shelter-based authorisation (was: origin_barangay_id vs user barangay_id).
        $this->authorizeHousehold($household);
        // DROP B2. And now the rule the picker always implied but the endpoint
        // never enforced.
        $this->assertHouseholdIsHere($household, $center);

        $this->distributeReliefTo($center, $household, $data);

        AuditLogger::log('created', $household, "Distributed relief to {$household->household_code}");

        return redirect()->route('barangay.relief.index')->with('success', 'Relief distribution logged.');
    }

    /**
     * Record stock received at the center (delivery from city / donations).
     *
     * DROP B1. Now captures a donor category (required), a donor name
     * (optional), a peso value and remarks -- and can create the relief item
     * itself when a donation arrives that is not on the sixteen-item list.
     *
     * The `source` free-text field is gone. It asked one vague question
     * ("CDRRMO delivery, donation") and got one vague answer, which is exactly
     * why the client could not report on where relief came from. Donor category
     * and donor name are the two facts they actually track, and the category is
     * a fixed list so it can be counted.
     *
     * CHANGE THIS METHOD AND CityAdmin\ShelterDetailController::receiveRelief()
     * TOGETHER. They are the same operation performed by two roles, and the
     * shared parts now live in RecordsReliefReceipt so they cannot drift again.
     */
    public function receive(Request $request)
    {
        $center = $this->centerOrFail();

        $data = $request->validate($this->reliefReceiptRules());

        $transaction = DB::transaction(function () use ($data, $center) {
            $good = $this->resolveReliefGood($data);

            return $this->applyReliefReceipt($center, $good, $data);
        });

        AuditLogger::log('created', $transaction, sprintf(
            'Recorded relief stock received at %s: %s %s from %s',
            $center->name,
            $transaction->quantity,
            $transaction->reliefGood?->name ?? 'item',
            $transaction->donorTypeLabel()
        ));

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

        return back()->with('success', 'Restock request submitted for CSWD Office approval.');
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

        return back()->with('success', 'Special item request submitted for CSWD Office approval.');
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
            // PHASE 9 ITEM 1. Loaded only when a term exists -- see the note in
            // Barangay\EvacueeProfilingController::search().
            ->when($term, fn ($q) => $q->with('members'))
            ->where('evacuation_center_id', $center->id)
            ->where('status', 'checked_in')
            // when(), not a required filter: a blank term is a legitimate query
            // meaning "everyone here", and it is what the prefill sends.
            ->when($term, fn ($q) => $q->whereHas('members', fn ($m) => $m->nameMatches($term)))
            ->orderByDesc('checked_in_at')
            ->limit(10)
            ->get()
            ->map(fn ($h) => [
                'id' => $h->id,
                'code' => $h->household_code,
                'head' => $h->headMember?->full_name ?? '-',
                'size' => $h->members_present,
                // PHASE 9 ITEM 1. Null unless the match was somebody other than
                // the head, so the picker only speaks up when it needs to.
                'matched' => $h->matchedMemberName($term),
            ]);

        return response()->json($results);
    }
}
