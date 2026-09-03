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
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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

        /* DROP C. The household checklist inside the Batch Distribute modal.
           SERVER-RENDERED, not fetched. The batch form needs EVERY family this
           shelter may hand relief to, and barangay.relief.recipients answers a
           deliberately narrower question -- it returns ten rows for a blank
           term, which is right for a type-ahead and wrong for a checklist. Three
           other pickers depend on that limit, so widening it to serve this
           screen would have changed all four. This is one shelter's households,
           which is exactly the case where server-rendering beats a fetch. */
        $batchHouseholds = collect();

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

            /* DROP C. Every checked-in household here, ordered by head name so
               an operator scanning for a family finds them where a paper list
               would put them.

               THE WHOLE POPULATION, not just $priority. A batch is issued to
               whoever is queued at the table, and a family who received rice
               yesterday is still standing in it today. Excluding them would
               have made the checklist quietly disagree with the room. The
               priority families are BADGED instead, computed from the ids
               $priority already resolved above rather than by a second query
               asking the same question -- two hand-written copies of one rule is
               how the two distribute paths drifted for a whole phase. */
            $priorityIds = $priority->pluck('id')->all();

            $batchHouseholds = Household::with('headMember')
                ->where('evacuation_center_id', $center->id)
                ->where('status', 'checked_in')
                ->get()
                ->sortBy(
                    fn ($h) => $h->headMember?->full_name ?? $h->household_code,
                    SORT_NATURAL | SORT_FLAG_CASE
                )
                ->values()
                ->map(function ($h) use ($priorityIds) {
                    $h->is_priority = in_array($h->id, $priorityIds, true);
                    return $h;
                });

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
            'restockRequests', 'specialRequests', 'receipts', 'receiptCount', 'donorTypes',
            'batchHouseholds'
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
     * DROP C -- one relief pack, many families, one submit.
     *
     * WHAT IT WRITES. Exactly what distribute() writes, N times: one
     * ReliefTransaction per household per relief good, each carrying its own
     * household_id, all sharing one batch_id. It cannot produce a row shape a
     * single distribution could not, because it does not write rows -- it calls
     * DistributesRelief::distributeReliefTo() in a loop.
     *
     * WHY PER-HOUSEHOLD ROWS AND NOT ONE AGGREGATE ROW. The "Priority: Not Yet
     * Received" panel on this screen queries whereDoesntHave on household_id.
     * An un-attributed aggregate row credits nobody, so the panel would keep
     * listing every family as unserved in the moment after every one of them had
     * been served -- a screen telling the operator to go and do the thing they
     * had just finished doing. Per-household attribution is the entire reason
     * this feature exists in this shape.
     *
     * WHAT IT DOES NOT DO. It does not modify distributeReliefTo(), which every
     * existing distribution in both roles runs through. Composing that method
     * gives this one row-level locking, shortage collection, aggregation of
     * duplicate item rows and per-household attribution for free, and means a
     * fault in this method can only break this method. That is the whole point
     * of building it this way rather than three lines shorter.
     *
     * SAME ITEMS TO EVERY FAMILY, by decision: a batch is the standard pack.
     * A family who needs something different gets the ordinary Distribute
     * modal, which is untouched.
     *
     * NO DATE FIELD, by consequence. distributeReliefTo() stamps
     * transaction_date with now(), exactly as the single-distribution path
     * does, so every row in a batch shares one date automatically. Offering a
     * date picker here would have produced a control the write path silently
     * ignores, which is worse than not offering one. Backdating a batch is
     * future work and would require editing the shared method.
     */
    public function batchDistribute(Request $request)
    {
        $center = $this->centerOrFail();

        /* Rules live here rather than in DistributesRelief. Gotcha 19 is about
           two hand-written copies of one rule drifting apart; there is exactly
           one batch path and there will not be a second, because batch is Camp
           Manager only. The item rules deliberately mirror
           reliefDistributionRules() -- bounded at both ends, same ceiling -- so
           a quantity accepted here is a quantity accepted there.

           `distinct` matters: a duplicated household id would otherwise pass
           validation, be deduplicated by the whereIn below, and make the
           expected-row assertion at the end of the transaction fail for a
           reason nobody could read off the screen. */
        $data = $request->validate([
            'households' => ['required', 'array', 'min:1', 'max:300'],
            'households.*' => ['required', 'integer', 'distinct', 'exists:households,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.relief_good_id' => ['required', 'exists:relief_goods,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:' . ReliefTransaction::MAX_QUANTITY],
            'remarks' => ['nullable', 'string', 'max:500'],
        ], [
            // Without these, an empty checklist posts and Laravel says "The
            // households field is required", naming a form key the operator has
            // never seen. The modal blocks this case client-side; this is what
            // catches the submit that arrives anyway.
            'households.required' => 'Tick at least one family before logging a batch distribution.',
            'households.array' => 'Tick at least one family before logging a batch distribution.',
            'households.max' => 'A single batch is limited to 300 families. Split it into two.',
            'items.required' => 'Choose at least one relief item and a quantity.',
        ]);

        $households = Household::with('headMember')
            ->whereIn('id', $data['households'])
            ->get();

        /* Authorisation and the checked-in-here rule, per household, BEFORE the
           transaction opens.

           assertHouseholdIsHere() is called rather than reimplemented, and its
           ValidationException is caught only so that every offending family can
           be named in one message instead of one per resubmit. The rule itself
           still has exactly one definition, in the trait, shared with both
           single-distribution paths.

           The realistic case is a stale page: the operator loaded this screen,
           somebody checked a family out, and the checklist still offers them. */
        $blocked = [];

        foreach ($households as $household) {
            $this->authorizeHousehold($household);

            try {
                $this->assertHouseholdIsHere($household, $center);
            } catch (ValidationException $e) {
                $blocked[] = $household->headMember?->full_name ?? $household->household_code;
            }
        }

        if ($blocked) {
            throw ValidationException::withMessages([
                'households' => array_merge(
                    ['Some of the families you ticked are no longer checked in at this shelter. Nothing has been given out. Reload the page and try again.'],
                    $blocked
                ),
            ]);
        }

        /* Per-family amounts, aggregated by the same helper the single path
           uses, so "Rice 5" entered twice becomes one line of 10 here exactly as
           it does there -- and the number quoted in a shortage message is the
           number the operator actually asked for. */
        $perFamily = $this->aggregateReliefItems($data['items']);
        ksort($perFamily);

        $familyCount = $households->count();
        $expectedRows = $familyCount * count($perFamily);
        $batchId = (string) Str::uuid();
        $startedAt = now();

        DB::transaction(function () use (
            $center, $households, $data, $perFamily, $familyCount, $expectedRows, $batchId, $startedAt
        ) {
            /* STEP 1 -- take every lock this batch will need, up front, ordered
               by relief good id ascending.

               distributeReliefTo() already orders its locks by good id within
               one call. Across N households in one outer transaction that is no
               longer enough: a family taking goods 3 and 7 followed by a family
               taking 5 and 7 acquires 3, 7, 5 overall, and a concurrent batch
               acquiring 5 before 3 is a deadlock. Taking the union of the rows
               first, in id order, makes this transaction's acquisition order
               strictly ascending no matter what each family is given. The inner
               calls then re-lock rows this transaction already holds, which
               InnoDB treats as a no-op.

               The realistic risk at one shelter with one Camp Manager is close
               to zero. It is five lines, and a deadlock surfaces as a random
               failure under exactly the load a defence demo does not have. */
            $onHand = [];

            foreach (array_keys($perFamily) as $goodId) {
                $inventory = ReliefInventory::where('evacuation_center_id', $center->id)
                    ->where('relief_good_id', $goodId)
                    ->lockForUpdate()
                    ->first();

                $onHand[$goodId] = $inventory?->quantity_on_hand ?? 0;
            }

            /* STEP 2 -- check the WHOLE batch against that locked stock before
               a single row is written.

               ALL OR NOTHING, by decision. Letting the first six families stand
               and failing on the seventh would leave the operator holding a
               half-applied batch with no way to see where it stopped: the
               Distribution Log would show six entries and the checklist would
               look identical to the one they just submitted. A batch that
               refuses cleanly can be retried; a batch that half-succeeds cannot
               be reasoned about.

               Every shortage is collected, not just the first, and each names
               the per-family amount, the family count and the total -- because
               "only 40 on hand" is baffling until you can see that 2 each for 23
               families is 46. */
            $shortages = [];

            foreach ($perFamily as $goodId => $quantity) {
                $required = $quantity * $familyCount;

                if ($onHand[$goodId] < $required) {
                    $good = ReliefGood::find($goodId);
                    $name = $good?->name ?? 'that item';
                    $unit = $good?->unit ? ' ' . $good->unit : '';

                    $shortages[] = "{$name}: {$quantity}{$unit} each for {$familyCount} families needs {$required}, only {$onHand[$goodId]} on hand.";
                }
            }

            if ($shortages) {
                throw ValidationException::withMessages([
                    'batch_items' => array_merge(
                        ['Not enough stock to complete this batch. Nothing has been given out.'],
                        $shortages
                    ),
                ]);
            }

            /* STEP 3 -- the write. One call per household, each of which is the
               ordinary single distribution. Its own stock check cannot fail now,
               because step 2 checked the total against locks this transaction
               still holds.

               distributeReliefTo() opens its own DB::transaction. Nested inside
               this one Laravel uses a savepoint, so an exception from any
               household still unwinds the entire batch. */
            foreach ($households as $household) {
                $this->distributeReliefTo($center, $household, $data);
            }

            /* STEP 4 -- stamp the rows this batch just wrote.
             *
             * WHY AFTER THE FACT AND NOT PASSED IN. distributeReliefTo() builds
             * its ReliefTransaction::create() payload from a fixed list of keys
             * and does not read batch_id from $data. Editing it to accept one
             * would mean editing the single method every distribution in both
             * roles runs through, days before a defence, to add a feature
             * neither of them uses. The project rule is to prefer the large
             * additive change to the small invasive one, so the stamping lives
             * here, in new code, and that file is not opened at all.
             *
             * THE ASSERTION IS WHAT MAKES THIS SAFE. The window is narrowed four
             * ways -- this shelter, this operator, these households, still
             * unstamped, created since this request began -- and then the
             * affected-row count is checked against the number this batch was
             * supposed to write. If anything else had somehow landed in the
             * window, the count differs and the whole transaction rolls back
             * rather than sweeping a stray row into the batch. It fails safe and
             * it fails loudly, which is the opposite of writing a wrong
             * attribution quietly.
             */
            $stamped = ReliefTransaction::where('evacuation_center_id', $center->id)
                ->where('type', 'distributed')
                ->whereNull('batch_id')
                ->where('recorded_by', auth()->id())
                ->whereIn('household_id', $households->pluck('id'))
                ->where('created_at', '>=', $startedAt)
                ->update(['batch_id' => $batchId]);

            if ($stamped !== $expectedRows) {
                throw ValidationException::withMessages([
                    'batch_items' => ["This batch could not be recorded cleanly: {$stamped} rows matched where {$expectedRows} were expected. Nothing has been given out. Please try again."],
                ]);
            }
        });

        /* One audit line for the batch, not one per household. Twelve families
           times two items would otherwise put twenty-four near-identical rows in
           the audit log for a single operator action, and an audit trail that
           buries what happened is only marginally better than one that misses
           it. The individual transactions carry their own household_id and are
           readable in the Distribution Log. */
        AuditLogger::log('created', $center, sprintf(
            'Batch relief distribution at %s: %d families, %d item(s) each, batch %s',
            $center->name,
            $familyCount,
            count($perFamily),
            $batchId
        ));

        return redirect()->route('barangay.relief.index')->with(
            'success',
            "Batch distribution logged for {$familyCount} " . ($familyCount === 1 ? 'family' : 'families') . '.'
        );
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
