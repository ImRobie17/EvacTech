<?php

namespace App\Http\Controllers\CityAdmin;

use App\Http\Controllers\Concerns\FiltersReports;
use App\Http\Controllers\Controller;
use App\Models\EvacuationCenter;
use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\ReliefGood;
use App\Models\ReliefInventory;
use App\Models\ReliefTransaction;
use App\Models\ShelterTransfer;
use App\Models\VulnerableClassification;
use App\Services\AuditLogger;
use App\Services\HouseholdMemberSync;
use App\Services\PresenceService;
use App\Services\TransferService;
use App\Support\AgeTier;
use App\Support\MemberRules;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * City Admin's own view of a single shelter.
 *
 * WHY THIS EXISTS: City Admin used to reach shelter detail by reusing the
 * Barangay controllers and Blade views through the `city.shelters.manage.*`
 * routes. Every action in those shared views then had to ask "which role am I
 * rendering for?" to pick a route -- and each new action was a fresh chance to
 * forget. That produced three separate bugs from one cause: the back link
 * landing on /barangay/shelter, the check-out 403, and Edit Family Group
 * navigating away instead of opening in place.
 *
 * This controller and `cityadmin/shelters/show.blade.php` are City-Admin-only.
 * Same database tables, separate presentation. There is no role branching
 * anywhere in either, because neither can be reached by any other role.
 *
 * The shelter is ALWAYS route-bound ({center}), so there is no session context
 * and no ResolvesCenter involvement.
 */
class ShelterDetailController extends Controller
{
    /* PHASE 8 ITEM 1. See Barangay\ShelterController for why the report filter
       trait is the right home for these two: it owns the DEFINITION of a
       vulnerable-group and age-group match, and reusing it is what keeps a
       filtered shelter screen and a filtered report selecting the same people. */
    use FiltersReports;

    public function __construct(
        private HouseholdMemberSync $sync,
        private TransferService $transfers,
    ) {
    }

    public const TABS = ['households', 'relief'];

    public function show(Request $request, EvacuationCenter $center)
    {
        $tab = in_array($request->input('tab'), self::TABS, true)
            ? $request->input('tab')
            : 'households';

        $center->loadMissing('barangay', 'assignedStaff');

        $data = [
            'center' => $center,
            'tab' => $tab,
            // Selectable only: the retired Senior Citizen / Infant rows must
            // never appear as something a staff member can tick.
            'classifications' => VulnerableClassification::selectable()->orderBy('name')->get(),
            'ageGroups' => AgeTier::options(),
            // PHASE 2 ITEM 8: destinations for the "Move to Shelter" modal.
            'transferCenters' => $this->transfers->centerOptions(),
            // PHASE 5 ITEM 8b: people this shelter has not accounted for after a
            // transfer. Separate from occupancy on purpose -- they are already
            // excluded from it.
            'unaccounted' => $this->transfers->unaccountedCountFor($center),
        ];

        $data += $tab === 'relief'
            ? $this->reliefData($request, $center)
            : $this->householdData($request, $center);

        return view('cityadmin.shelters.show', $data);
    }

    // -----------------------------------------------------------------
    // Households tab
    // -----------------------------------------------------------------

    private function householdData(Request $request, EvacuationCenter $center): array
    {
        // PHASE 9 ITEM 2. Same eager load, same reason, as the barangay roster.
        $query = Household::with(['headMember', 'actingHeadMember', 'originBarangay'])
            ->where('evacuation_center_id', $center->id);

        if ($search = trim((string) $request->input('q'))) {
            // PHASE 9 ITEM 1. Any member, not only the head. The sort subquery
            // below deliberately still selects the head.
            $query->whereHas('members', fn ($q) => $q->nameMatches($search));
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($barangayId = $request->input('barangay')) {
            $query->where('origin_barangay_id', $barangayId);
        }

        /* PHASE 8 ITEM 1. Households containing at least one member matching the
           Vulnerable Group / Age Group selects. Same trait, same primitive, same
           definitions the barangay shelter list and every report already use --
           so the two roles cannot disagree about which families "Pregnant Woman"
           or "Teenage" picks out of the same shelter.

           Applied before the sort and before paginate(), so the ordering runs
           over the filtered set and page 2 is page 2 of the matches. */
        $query = $this->filterHouseholds($query, $this->shelterFilters($request));

        if ($request->input('sort') === 'name') {
            $query->orderBy(
                HouseholdMember::select('full_name')
                    ->whereColumn('household_members.household_id', 'households.id')
                    ->where('is_household_head', true)
                    ->limit(1)
            );
        } else {
            $query->latest('checked_in_at');
        }

        $households = $query->paginate(15)->withQueryString();

        return [
            'households' => $households,
            'originBarangays' => \App\Models\Barangay::orderBy('name')->get(),
            // PHASE 2 ITEM 8: one query for the page, so each row knows whether
            // a transfer is already in progress for that family.
            'openTransferHouseholdIds' => ShelterTransfer::whereIn('household_id', $households->pluck('id'))
                ->open()
                ->pluck('household_id')
                ->all(),
        ];
    }

    /** Check a registered household into THIS shelter. */
    public function checkIn(Request $request, EvacuationCenter $center, Household $household)
    {
        $data = $request->validate([
            'present' => ['required', 'array', 'min:1'],
            'present.*' => ['integer'],
            'acting_head_member_id' => ['nullable', 'integer'],
        ]);

        /* PHASE 9 ITEM 3 -- shelter exclusivity, and a REAL divergence closed.
           This used to read `status === 'checked_in' && evacuation_center_id ===
           $center->id`, so it blocked a duplicate check-in HERE but happily
           accepted a household still checked in somewhere ELSE. Occupancy stayed
           correct at both ends -- both recalcs below have always been here -- but
           the family moved between shelters with NO shelter_transfers row, so
           the transfer log showed a family that never travelled and the move had
           no lifecycle, no origin acknowledgement and no arrival reconciliation.

           This is now the same rule, and deliberately the same sentence, as
           Barangay\ShelterController::checkIn(). Gotcha 19: a rule written in two
           role controllers drifts, and this is the drift it produced.

           City Admin loses nothing. CityAdmin\TransferController::store() calls
           request() then confirm($transfer, $user, true), so a CDRRMO-initiated
           transfer is auto-approved and never queues -- relocating a family is
           one extra screen, not a lost power. */
        if ($household->status === 'checked_in') {
            $where = $household->evacuation_center_id === $center->id
                ? 'here'
                : "at {$household->evacuationCenter?->name}";

            return back()->withErrors([
                'household' => "This household is already checked in {$where}. Use Transfer instead.",
            ]);
        }

        $present = array_map('intval', $data['present']);

        // PHASE 9 ITEM 2 -- identical rule to the barangay side, and identically
        // worded, for the same reason the guard above now is.
        $actingHeadId = null;
        $headAbsent = $household->head_member_id
            && ! in_array((int) $household->head_member_id, $present, true);

        if ($headAbsent) {
            $actingHeadId = $data['acting_head_member_id'] ?? null;

            if (! $actingHeadId) {
                return back()->withErrors([
                    'acting_head_member_id' => 'The household head is not among the people you ticked. '
                        . 'Choose someone present to stand in as head for this stay.',
                ])->withInput();
            }

            if (! $household->members()->whereKey($actingHeadId)->exists()) {
                return back()->withErrors([
                    'acting_head_member_id' => 'That person is not a member of this household.',
                ])->withInput();
            }

            if (! in_array((int) $actingHeadId, $present, true)) {
                return back()->withErrors([
                    'acting_head_member_id' => 'The stand-in head must be someone who is present at the shelter.',
                ])->withInput();
            }
        }

        $previous = $household->evacuationCenter;

        DB::transaction(function () use ($household, $center, $previous, $data, $actingHeadId) {
            $household->members()->update(['is_present' => false]);
            $household->members()->whereIn('id', $data['present'])->update(['is_present' => true]);

            $household->update([
                'evacuation_center_id' => $center->id,
                'status' => 'checked_in',
                'checked_in_at' => now(),
                'checked_out_at' => null,
                'members_present' => $household->members()->where('is_present', true)->count(),
                'acting_head_member_id' => $actingHeadId,
            ]);

            $center->recalcOccupancy();
            if ($previous && $previous->id !== $center->id) {
                $previous->recalcOccupancy();
            }
        });

        $household->refresh();

        $note = "City Admin checked in {$household->household_code} at {$center->name} ({$household->members_present} present)";

        if ($household->acting_head_member_id) {
            $acting = $household->actingHeadMember?->full_name ?? 'a member';
            $substantive = $household->headMember?->full_name ?? 'the household head';
            $note .= ". {$acting} is standing in as head for this stay; {$substantive} remains the household head and is not present";
        }

        AuditLogger::log('updated', $household, $note);

        $message = "Household {$household->household_code} checked in.";
        if ($household->acting_head_member_id) {
            $message .= ' ' . ($household->actingHeadMember?->full_name ?? 'A member')
                . ' is standing in as head until the household head arrives.';
        }

        return $this->backToTab($center, 'households', $message);
    }

    /**
     * PHASE 5 ITEM 8b -- the tick list for the Update Presence modal.
     *
     * Same contract as the barangay twin: the block reason is RETURNED, not
     * thrown, so the modal explains itself. PresenceService::update() re-checks
     * it under a lock.
     */
    public function presence(EvacuationCenter $center, Household $household)
    {
        // Consistency guard only -- City Admin may operate any shelter, but the
        // household must actually belong to the one in the URL. Same check the
        // check-out path makes.
        abort_if($household->evacuation_center_id !== $center->id, 404,
            'This household is not registered at this shelter.');

        $service = app(PresenceService::class);

        return response()->json([
            'id' => $household->id,
            'code' => $household->household_code,
            'head' => $household->headMember?->full_name,
            'center' => $center->name,
            'present' => (int) $household->members_present,
            'total' => $household->members()->count(),
            'blocked' => $service->blockedReason($household, auth()->user()),
            'members' => $service->checklist($household),
            // PHASE 9 ITEM 2 -- same payload as the barangay presence endpoint,
            // because partials/presence-modal is shared and must not branch.
            'acting_head_member_id' => $household->acting_head_member_id,
            'acting_head' => $household->actingHeadMember?->full_name,
            // Derived once on the model rather than dug out of the members array:
            // two of these three payloads do not carry per-member is_present, and
            // a field the viewer silently never finds is worse than no field.
            'head_is_present' => $household->substantiveHeadIsPresent(),
            'head_member_id' => $household->head_member_id,
        ]);
    }

    /** PHASE 5 ITEM 8b -- write the corrected presence. */
    public function updatePresence(Request $request, EvacuationCenter $center, Household $household)
    {
        abort_if($household->evacuation_center_id !== $center->id, 404,
            'This household is not registered at this shelter.');

        $data = $request->validate([
            'present' => ['required', 'array', 'min:1'],
            'present.*' => ['integer'],
            // PHASE 9 ITEM 2. Only submitted when the modal offered the choice.
            'acting_head_action' => ['nullable', 'in:revert,keep'],
        ], [
            'present.required' => 'Tick at least one person who is present at the shelter.',
        ]);

        app(PresenceService::class)->update(
            $household,
            $data['present'],
            $request->user(),
            $data['acting_head_action'] ?? null
        );

        $household->refresh();

        return $this->backToTab($center, 'households',
            "Presence updated. {$household->household_code} now has {$household->members_present} present.");
    }

    public function checkOut(EvacuationCenter $center, Household $household)
    {
        // Consistency guard only -- City Admin may operate any shelter, but the
        // household must actually belong to the one in the URL.
        abort_if($household->evacuation_center_id !== $center->id, 404,
            'This household is not registered at this shelter.');

        if ($household->status !== 'checked_in') {
            return back()->withErrors(['household' => 'This household is not currently checked in.']);
        }

        // PHASE 2 ITEM 8: same guard as the barangay side. A family with a
        // transfer in progress is still counted here on purpose, and checking
        // them out would leave the destination with nothing to receive.
        if ($open = $household->openTransfer()) {
            return back()->withErrors([
                'household' => "This household has a shelter transfer in progress ({$open->statusLabel()}). Cancel or complete the transfer first.",
            ]);
        }

        DB::transaction(function () use ($household, $center) {
            $household->members()->update(['is_present' => false]);
            $household->update([
                'status' => 'checked_out',
                'checked_out_at' => now(),
                'members_present' => 0,
                // PHASE 9 ITEM 2. A stand-in head is scoped to one stay.
                'acting_head_member_id' => null,
            ]);
            $center->recalcOccupancy();
        });

        AuditLogger::log('updated', $household, "City Admin checked out {$household->household_code}");

        return $this->backToTab($center, 'households', "Household {$household->household_code} checked out.");
    }

    /** Household JSON for the inline Check-in and Edit Family Group modals. */
    public function household(EvacuationCenter $center, Household $household)
    {
        $household->load(['members.vulnerableClassifications', 'headMember', 'originBarangay', 'evacuationCenter']);

        return response()->json([
            'id' => $household->id,
            'code' => $household->household_code,
            'address' => $household->origin_address,
            'origin_barangay_id' => $household->origin_barangay_id,
            'origin_barangay' => $household->originBarangay?->name,
            'status' => $household->status,
            /* PHASE 6 ITEM 10. The read-only view modal reads these. The edit
               modal on this page ignores them, so adding them costs one
               already-loaded relation and nothing else. */
            'center' => $household->evacuationCenter?->name,
            'checked_in_at' => $household->checked_in_at?->toIso8601String(),
            'checked_out_at' => $household->checked_out_at?->toIso8601String(),
            'members_present' => $household->members_present,
            'number_of_members' => $household->number_of_members,
            // PHASE 9 ITEM 2. The view modal is reachable from every household
            // list, so carrying the stand-in here is what makes it visible from
            // lists that do not print it in their own table.
            'acting_head_member_id' => $household->acting_head_member_id,
            'acting_head' => $household->actingHeadMember?->full_name,
            // Derived once on the model rather than dug out of the members array:
            // two of these three payloads do not carry per-member is_present, and
            // a field the viewer silently never finds is worse than no field.
            'head_is_present' => $household->substantiveHeadIsPresent(),
            'single_headed' => $household->isSingleHeaded(),
            'head_member_id' => $household->head_member_id,
            'members' => $household->members->map(fn ($m) => [
                'id' => $m->id,
                'full_name' => $m->full_name,
                'last_name' => trim(explode(',', $m->full_name)[0] ?? ''),
                'first_name' => trim(explode(' ', trim(explode(',', $m->full_name)[1] ?? ''))[0] ?? ''),
                'birthdate' => $m->birthdate?->format('Y-m-d'),
                'sex' => $m->sex,
                'is_head' => (bool) $m->is_household_head,
                'is_present' => (bool) $m->is_present,
                'age_tier' => $m->ageTier(),
                'age_tier_label' => $m->ageTierShortLabel(),
                'age_tier_fallback' => $m->age_tier_fallback,
                // Retired tags are filtered out so the edit modal never offers
                // Senior Citizen as a live checkbox.
                'tags' => $m->vulnerableClassifications
                    ->where('is_selectable', true)
                    ->values()
                    ->map(fn ($c) => ['id' => $c->id, 'code' => $c->code, 'name' => $c->name])
                    ->values(),
            ])->values(),
        ]);
    }

    /**
     * Households for the Check-in modal search.
     *
     * FIX: this previously filtered `status != checked_in`, which silently
     * returned nothing whenever the household you searched for was already
     * checked in somewhere -- including at this very shelter. Checking in a
     * household that is checked in ELSEWHERE is a legitimate transfer, and
     * hiding it just looked like the search was broken.
     *
     * All households are now returned with their status and current shelter so
     * the UI can show where they are; checkIn() still rejects a duplicate
     * check-in at this same shelter.
     */
    public function searchHouseholds(Request $request, EvacuationCenter $center)
    {
        $term = trim((string) $request->input('q'));

        $results = Household::with(['headMember', 'evacuationCenter', 'originBarangay'])
            ->withCount(['members as absent_count' => fn ($m) => $m->where('is_present', false)])
            // PHASE 9 ITEM 1. Loaded only when a term exists, for the label.
            ->when($term, fn ($q) => $q->with('members'))
            ->when($term, fn ($q) => $q->whereHas('members', fn ($m) => $m->nameMatches($term)))
            /* PHASE 9 ITEMS 3 + 5. Was a blanket exclusion of everyone checked
               in here, which made the picker a dead end for the commonest real
               situation on a shelter floor: a family is already checked in and
               one more of them has just walked through the door. Staff reach for
               Check-in, because that is what it is called, and the picker
               refused to show the family at all.

               So the exclusion now only removes families who are checked in here
               with EVERYONE already present -- for whom there is genuinely
               nothing to do. A family with somebody still absent is shown, and
               routed to presence correction instead. */
            ->whereNot(fn ($q) => $q
                ->where('evacuation_center_id', $center->id)
                ->where('status', 'checked_in')
                ->whereDoesntHave('members', fn ($m) => $m->where('is_present', false)))
            ->orderByDesc('updated_at')
            ->limit(10)
            ->get()
            ->map(fn ($h) => [
                'id' => $h->id,
                'code' => $h->household_code,
                'head' => $h->headMember?->full_name ?? '-',
                'size' => $h->number_of_members,
                'status' => $h->status,
                'current_center' => $h->evacuationCenter?->name,
                'current_center_id' => $h->evacuation_center_id,
                'origin_barangay' => $h->originBarangay?->name,
                'absent' => (int) $h->absent_count,
                'members_present' => (int) $h->members_present,
                // PHASE 9 ITEM 1. Null unless the match was somebody other than
                // the head, so the picker only speaks up when it needs to.
                'matched' => $h->matchedMemberName($term),
                /* Which control this row actually needs. Decided on the server so
                   the two pickers cannot disagree about it, and so the rule sits
                   beside the check-in guard that enforces the same thing. */
                'action' => $h->checkinAction($center->id),
            ]);

        return response()->json($results);
    }

    /**
     * Edit Family Group, submitted from the modal ON THIS PAGE. Previously this
     * redirected City Admin to the barangay Evacuee Profiling screen with
     * ?edit=<id>, which is the navigation bug being removed.
     */
    public function updateHousehold(Request $request, EvacuationCenter $center, Household $household)
    {
        abort_if($household->evacuation_center_id !== $center->id, 404,
            'This household is not registered at this shelter.');

        $data = $request->validate([
            'origin_barangay_id' => ['required', 'exists:barangays,id'],
            'address' => ['required', 'string', 'max:255'],
            'members' => ['required', 'array', 'min:1'],
            'members.*.id' => ['nullable', 'integer'],
            // PHASE 3 ITEM 9. Blank on a non-head row is allowed:
            // HouseholdMemberSync fills it from the head's surname, which is
            // the common case and saves retyping it for every child during a
            // surge. The HEAD's surname stays required -- inheritance has to
            // come from somewhere, and an empty one would build every
            // full_name in the family as ", Juan".
            //
            // members.0 is the head on every form that posts here: the head row
            // is rendered at index 0 and carries the is_head hidden input.
            // HouseholdMemberSync resolves the head the same way and treats a
            // blank head surname as "do not inherit", so a future form that
            // flagged is_head elsewhere would degrade to a validation error
            // rather than to silently wrong names.
            'members.*.last_name' => ['nullable', 'string', 'max:100'],
            'members.0.last_name' => ['required', 'string', 'max:100'],
            'members.*.first_name' => ['required', 'string', 'max:100'],
            'members.*.middle_name' => ['nullable', 'string', 'max:100'],
            // PHASE 7 ITEM 3. Both bounds now live in MemberRules so the rule
            // and the input's min/max attributes cannot drift apart.
            'members.*.birthdate' => MemberRules::birthdate(),
            'members.*.age_group' => [
                'required_without:members.*.birthdate',
                'nullable',
                'in:' . implode(',', array_keys(AgeTier::options())),
            ],
            'members.*.sex' => ['required', 'in:male,female'],
            'members.*.is_head' => ['nullable'],
            // PHASE 7 ITEM 2 -- rejects Pregnant Woman / Lactating Mother on a
            // member whose sex is not female. Needs the request because the
            // rule reads the sibling sex field on the same member row.
            'members.*.tags' => MemberRules::tags($request),
            'members.*.tags.*' => ['integer', 'exists:vulnerable_classifications,id'],
        ], [
            // PHASE 7 ITEM 3. This screen had no messages array; without one
            // the after_or_equal failure prints the raw boundary date and the
            // attribute path, which tells an operator nothing useful.
            'members.*.birthdate.before_or_equal' => 'A date of birth cannot be in the future.',
            'members.*.birthdate.after_or_equal' => 'Check the date of birth -- nobody in the system can be older than '
                . MemberRules::MAX_AGE_YEARS . ' years.',
        ]);

        DB::transaction(function () use ($household, $center, $data) {
            $household->update([
                'origin_address' => $data['address'],
                'origin_barangay_id' => $data['origin_barangay_id'],
            ]);

            // Phase 2: the four copy-pasted syncMembers() blocks (this was one
            // of them) are replaced by a single service. Auto-tagging Senior /
            // Infant is gone -- those are derived age tiers now.
            $this->sync->sync($household, $data['members'], keepPresence: true);

            $household->update([
                'number_of_members' => $household->members()->count(),
                'members_present' => $household->status === 'checked_in'
                    ? $household->members()->where('is_present', true)->count()
                    : 0,
            ]);

            $center->recalcOccupancy();
        });

        AuditLogger::log('updated', $household, "City Admin updated family group {$household->household_code}");

        return $this->backToTab($center, 'households', 'Family group updated.');
    }

    // -----------------------------------------------------------------
    // Relief tab
    // -----------------------------------------------------------------

    private function reliefData(Request $request, EvacuationCenter $center): array
    {
        $distributed = ReliefTransaction::where('evacuation_center_id', $center->id)
            ->where('type', 'distributed');

        $remaining = ReliefInventory::where('evacuation_center_id', $center->id)->sum('quantity_on_hand');

        $last7 = (clone $distributed)
            ->where('transaction_date', '>=', Carbon::today()->subDays(6))
            ->sum('quantity');
        $dailyRate = $last7 / 7;

        $logQuery = ReliefTransaction::with(['household.headMember', 'reliefGood', 'recordedBy'])
            ->where('evacuation_center_id', $center->id)
            ->where('type', 'distributed');

        if ($search = trim((string) $request->input('q'))) {
            /* PHASE 9 ITEM 1. Rooted on ReliefTransaction rather than Household,
               which is exactly why nameMatches() is a HouseholdMember scope --
               both roots land on the same builder. */
            $logQuery->whereHas('household.members', fn ($q) => $q->nameMatches($search));
        }

        return [
            'stats' => [
                'received' => ReliefTransaction::where('evacuation_center_id', $center->id)
                    ->whereIn('type', ['received', 'allocated_in'])->sum('quantity'),
                'distributed' => (clone $distributed)->sum('quantity'),
                'remaining' => $remaining,
                'days_left' => $dailyRate > 0 ? (int) floor($remaining / $dailyRate) : null,
            ],
            'log' => $logQuery->latest('created_at')->paginate(15)->withQueryString(),
            'inventory' => ReliefInventory::with('reliefGood')
                ->where('evacuation_center_id', $center->id)->get(),
            'goods' => ReliefGood::orderBy('name')->get(),
        ];
    }

    /** Record stock received at this shelter (city delivery or donation). */
    public function receiveRelief(Request $request, EvacuationCenter $center)
    {
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

        AuditLogger::log('created', $center, "City Admin recorded relief stock received at {$center->name}");

        return $this->backToTab($center, 'relief', 'Stock received and inventory updated.');
    }

    /** Distribute relief to a household at this shelter. */
    public function distributeRelief(Request $request, EvacuationCenter $center)
    {
        $data = $request->validate([
            'household_id' => ['required', 'exists:households,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.relief_good_id' => ['required', 'exists:relief_goods,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);

        $household = Household::findOrFail($data['household_id']);
        abort_if($household->evacuation_center_id !== $center->id, 404,
            'This household is not registered at this shelter.');

        DB::transaction(function () use ($data, $center, $household) {
            foreach ($data['items'] as $item) {
                $inventory = ReliefInventory::firstOrCreate(
                    ['evacuation_center_id' => $center->id, 'relief_good_id' => $item['relief_good_id']],
                    ['quantity_on_hand' => 0, 'reorder_level' => 0]
                );

                if ($inventory->quantity_on_hand < $item['quantity']) {
                    abort(422, 'Not enough stock of ' . $inventory->reliefGood->name
                        . " (on hand: {$inventory->quantity_on_hand}).");
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

        AuditLogger::log('created', $household, "City Admin distributed relief to {$household->household_code}");

        return $this->backToTab($center, 'relief', 'Relief distribution logged.');
    }

    /**
     * Checked-in households AT THIS SHELTER, for the Distribute modal search.
     *
     * Scoping is deliberate -- you cannot hand relief to someone who is not here.
     * If this returns nothing, no household is currently checked in at this
     * shelter: check someone in on the Households tab first.
     */
    public function searchReliefRecipients(Request $request, EvacuationCenter $center)
    {
        $term = trim((string) $request->input('q'));

        $results = Household::with('headMember')
            // PHASE 9 ITEM 1. Loaded only when a term exists, for the label.
            ->when($term, fn ($q) => $q->with('members'))
            ->where('evacuation_center_id', $center->id)
            ->where('status', 'checked_in')
            ->when($term, fn ($q) => $q->whereHas('members', fn ($m) => $m->nameMatches($term)))
            ->limit(10)
            ->get()
            ->map(fn ($h) => [
                'id' => $h->id,
                'code' => $h->household_code,
                'head' => $h->headMember?->full_name ?? '-',
                'size' => $h->members_present,
                // PHASE 9 ITEM 1. Mirrors Barangay\ReliefController::searchRecipients().
                'matched' => $h->matchedMemberName($term),
            ]);

        return response()->json($results);
    }

    // -----------------------------------------------------------------

    private function backToTab(EvacuationCenter $center, string $tab, string $message)
    {
        return redirect()
            ->to(route('city.shelters.show', $center) . '?tab=' . $tab)
            ->with('success', $message);
    }
}
