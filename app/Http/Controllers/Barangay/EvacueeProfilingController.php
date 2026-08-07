<?php

namespace App\Http\Controllers\Barangay;

use App\Models\Barangay;
use App\Models\EvacuationCenter;
use App\Models\Household;
use App\Models\VulnerableClassification;
use App\Services\AuditLogger;
use App\Services\HouseholdMemberSync;
use App\Support\AgeTier;
use App\Support\HouseholdCode;
use App\Support\MemberRules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EvacueeProfilingController extends BarangayController
{
    public function __construct(private HouseholdMemberSync $sync)
    {
    }

    public function index(Request $request, ?EvacuationCenter $routeCenter = null)
    {
        $center = $this->center($routeCenter);

        $query = Household::with(['headMember', 'originBarangay', 'evacuationCenter', 'members.vulnerableClassifications']);

        // Scope by SHELTER, not by the staff member's barangay. Households not yet
        // placed in a shelter (registered, awaiting check-in) stay visible so the
        // record can be completed.
        if ($center) {
            $query->where(fn ($q) => $q
                ->where('evacuation_center_id', $center->id)
                ->orWhereNull('evacuation_center_id'));
        } else {
            $query->whereIn('evacuation_center_id', auth()->user()->assignedCenterIds());
        }

        if ($search = trim((string) $request->input('q'))) {
            $query->whereHas('members', fn ($q) => $q
                ->where('is_household_head', true)
                ->where('full_name', 'like', "%{$search}%"));
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($barangayId = $request->input('barangay')) {
            $query->where('origin_barangay_id', $barangayId);
        }

        // Phase 2 item 6: age group and category are now SEPARATE filters.
        // They used to be one dropdown, because Senior Citizen and Infant were
        // classifications. Filtering by both at once means "households with at
        // least one member in this tier AND at least one member with this tag",
        // which is what an operator looking for, say, pregnant women in a
        // family with infants actually wants.
        if ($tier = $request->input('age_group')) {
            if (AgeTier::isValid($tier)) {
                $query->whereHas('members', fn ($q) => $q
                    ->whereRaw(AgeTier::sqlCase() . ' = ?', [$tier]));
            }
        }

        if ($vuln = $request->input('category')) {
            $query->whereHas('members.vulnerableClassifications', fn ($q) => $q
                ->where('vulnerable_classifications.id', $vuln));
        }

        // Derived from members_present == 1 on a checked-in family, never a tag.
        if ($request->boolean('single_headed')) {
            $query->singleHeaded();
        }

        $households = $query->latest()->paginate(15)->withQueryString();

        // Only SELECTABLE classifications reach the UI. The retired Senior
        // Citizen / Infant rows still exist for history but must never be
        // offered as something to tag or filter by.
        $classifications = VulnerableClassification::selectable()->orderBy('name')->get();
        $ageGroups = AgeTier::options();

        $barangays = Barangay::orderBy('name')->get();
        $defaultBarangayId = $center?->barangay_id;

        return view('barangay.evacuees.index', compact(
            'households', 'classifications', 'ageGroups', 'center', 'barangays', 'defaultBarangayId'
        ));
    }

    /** Register a new household (Add Evacuee modal). checkin=1 also checks them in. */
    public function store(Request $request)
    {
        $data = $this->validateHousehold($request);
        $checkin = $request->boolean('checkin');
        $center = $checkin ? $this->centerOrFail() : $this->center();

        // The retry wraps the transaction, never the other way round: a duplicate
        // key rolls the transaction back, so a retry needs a clean one.
        $household = HouseholdCode::attempt(fn ($code) => DB::transaction(function () use ($code, $data, $checkin, $center) {
            $household = Household::create([
                'household_code' => $code,
                'origin_barangay_id' => $data['origin_barangay_id'],
                'origin_address' => $data['address'],
                'number_of_members' => count($data['members']),
                'status' => 'registered',
                'registered_by' => auth()->id(),
            ]);

            $this->sync->sync($household, $data['members'], checkin: $checkin);

            if ($checkin) {
                $present = $household->members()->where('is_present', true)->count();
                $household->update([
                    'evacuation_center_id' => $center->id,
                    'status' => 'checked_in',
                    'checked_in_at' => now(),
                    'checked_out_at' => null,
                    'members_present' => $present,
                ]);
                $center->recalcOccupancy();
            }

            return $household;
        }));

        AuditLogger::log('created', $household,
            "Registered household {$household->household_code}" . ($checkin ? " and checked in at {$center->name}" : ''));

        /* PHASE 6 ITEM 11. Was a hard redirect to barangay.evacuees.index.
           Registration can now start from the Shelter page, and sending someone
           to Evacuee Profiling after they registered a family at their shelter
           is the same complaint as the edit redirection: the operator ends up
           somewhere they did not ask to be, mid-surge.

           back() returns whichever page the form was posted from. Posting from
           Evacuee Profiling still lands on Evacuee Profiling, so nothing about
           that screen changes. */
        return redirect()->back()
            ->with('success', "Household {$household->household_code} registered" . ($checkin ? ' and checked in.' : '.'));
    }

    /** Load one household with members + tags (JSON, used by Edit Family Group / Check-in modals). */
    public function show(Household $household)
    {
        $this->authorizeHousehold($household);

        $household->load(['members.vulnerableClassifications', 'headMember', 'evacuationCenter', 'originBarangay']);

        return response()->json([
            'id' => $household->id,
            'code' => $household->household_code,
            'address' => $household->origin_address,
            'origin_barangay_id' => $household->origin_barangay_id,
            'origin_barangay' => $household->originBarangay?->name,
            'status' => $household->status,
            /* PHASE 6 ITEM 10. The read-only view modal shows check-in and
               check-out times, which nothing else on this payload carried.
               ISO 8601 rather than a display string: formatting is the view's
               job, and a pre-formatted date here would have to be duplicated
               the moment a second consumer wanted it differently. */
            'checked_in_at' => $household->checked_in_at?->toIso8601String(),
            'checked_out_at' => $household->checked_out_at?->toIso8601String(),
            'members_present' => $household->members_present,
            'number_of_members' => $household->number_of_members,
            'center' => $household->evacuationCenter?->name,
            'center_id' => $household->evacuation_center_id,
            'head_member_id' => $household->head_member_id,
            'single_headed' => $household->isSingleHeaded(),
            'members' => $household->members->map(fn ($m) => [
                'id' => $m->id,
                'full_name' => $m->full_name,
                'birthdate' => $m->birthdate?->format('Y-m-d'),
                'sex' => $m->sex,
                'is_head' => $m->is_household_head,
                'is_present' => $m->is_present,
                // The derived tier, plus the raw fallback so the edit form can
                // tell "chosen by hand" from "computed from a birthdate".
                'age_tier' => $m->ageTier(),
                'age_tier_label' => $m->ageTierShortLabel(),
                'age_tier_fallback' => $m->age_tier_fallback,
                // Retired tags are filtered out: the edit form must not present
                // Senior Citizen as a live checkbox.
                'tags' => $m->vulnerableClassifications
                    ->where('is_selectable', true)
                    ->values()
                    ->map(fn ($c) => ['id' => $c->id, 'code' => $c->code, 'name' => $c->name]),
            ]),
        ]);
    }

    /** Edit Family Group: update address/members, add new members, retag. */
    public function update(Request $request, Household $household)
    {
        $this->authorizeHousehold($household);
        $data = $this->validateHousehold($request);

        DB::transaction(function () use ($household, $data) {
            $household->update([
                'origin_address' => $data['address'],
                'origin_barangay_id' => $data['origin_barangay_id'],
            ]);
            $this->sync->sync($household, $data['members'], keepPresence: true);
            $household->update([
                'number_of_members' => $household->members()->count(),
                'members_present' => $household->status === 'checked_in'
                    ? $household->members()->where('is_present', true)->count()
                    : 0,
            ]);

            // Member counts can change during an edit, so the shelter total moves.
            $household->evacuationCenter?->recalcOccupancy();
        });

        AuditLogger::log('updated', $household, "Updated family group {$household->household_code}");

        return redirect()->back()->with('success', 'Family group updated.');
    }

    /** Remove household (business rule: only when not currently checked in). */
    public function destroy(Household $household)
    {
        $this->authorizeHousehold($household);

        if ($household->status === 'checked_in') {
            return back()->withErrors(['household' => 'Check the household out before removing it.']);
        }

        $code = $household->household_code;
        $center = $household->evacuationCenter;
        $household->delete();
        $center?->recalcOccupancy();

        AuditLogger::log('deleted', $household, "Removed household {$code}");

        return back()->with('success', "Household {$code} removed.");
    }

    /** JSON search by head name (used by Check-in Family + Distribute Relief). */
    public function search(Request $request)
    {
        $term = trim((string) $request->input('q'));
        $user = auth()->user();

        $results = Household::with(['headMember', 'evacuationCenter', 'originBarangay'])
            ->where(fn ($q) => $q
                ->whereIn('evacuation_center_id', $user->assignedCenterIds())
                ->orWhereNull('evacuation_center_id'))
            ->when($term, fn ($q) => $q->whereHas('members', fn ($m) => $m
                ->where('is_household_head', true)
                ->where('full_name', 'like', "%{$term}%")))
            ->limit(10)
            ->get()
            ->map(fn ($h) => [
                'id' => $h->id,
                'code' => $h->household_code,
                'head' => $h->headMember?->full_name ?? '-',
                'size' => $h->number_of_members,
                'status' => $h->status,
                'center' => $h->evacuationCenter?->name,
                'origin_barangay' => $h->originBarangay?->name,
                'single_headed' => $h->isSingleHeaded(),
            ]);

        return response()->json($results);
    }

    // ---------------------------------------------------------------

    private function validateHousehold(Request $request): array
    {
        return $request->validate([
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

            // Phase 2: birthdate is OPTIONAL so staff can tag a family fast
            // during a surge and complete the record later. The age group is
            // then required in its place -- one tap, and it keeps "Unknown" off
            // a form a City Social Welfare officer signs.
            // PHASE 7 ITEM 3. Both bounds now live in MemberRules so the rule
            // and the input's min/max attributes cannot drift apart.
            'members.*.birthdate' => MemberRules::birthdate(),
            'members.*.age_group' => [
                'required_without:members.*.birthdate',
                'nullable',
                'in:' . implode(',', array_keys(AgeTier::options())),
            ],

            // Sex stays REQUIRED: every row of the IDP Monitoring Form splits
            // Male/Female with a reconciling TOTAL, and an unknown sex makes the
            // age table, the category table and the headcount disagree.
            'members.*.sex' => ['required', 'in:male,female'],

            'members.*.is_head' => ['nullable'],
            'members.*.is_present' => ['nullable'],
            // PHASE 7 ITEM 2 -- rejects Pregnant Woman / Lactating Mother on a
            // member whose sex is not female. Needs the request because the
            // rule reads the sibling sex field on the same member row.
            'members.*.tags' => MemberRules::tags($request),
            'members.*.tags.*' => ['integer', 'exists:vulnerable_classifications,id'],
        ], [
            'origin_barangay_id.required' => 'Select the barangay this family came from.',
            'members.*.age_group.required_without' => 'Choose an age group for any member without a date of birth.',
            'members.*.sex.required' => 'Sex is required for every member.',
            'members.*.birthdate.before_or_equal' => 'A date of birth cannot be in the future.',
            'members.*.birthdate.after_or_equal' => 'Check the date of birth -- nobody in the system can be older than '
                . MemberRules::MAX_AGE_YEARS . ' years.',
        ]);
    }
}
