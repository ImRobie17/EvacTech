<?php

namespace App\Http\Controllers\CityAdmin;

use App\Http\Controllers\Controller;
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

class EvacueeProfilingController extends Controller
{
    public function __construct(private HouseholdMemberSync $sync)
    {
    }

    public function index(Request $request)
    {
        // City-wide: every household in every shelter, filterable.
        $query = Household::with(['headMember', 'originBarangay', 'evacuationCenter', 'members.vulnerableClassifications']);

        if ($search = trim((string) $request->input('q'))) {
            // PHASE 9 ITEM 1. Any member, not only the head.
            $query->whereHas('members', fn ($q) => $q->nameMatches($search));
        }
        if ($barangay = $request->input('barangay')) {
            $query->where('origin_barangay_id', $barangay);
        }
        if ($shelter = $request->input('shelter')) {
            $query->where('evacuation_center_id', $shelter);
        }
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        // Phase 2 item 6: separate age-group and category filters.
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

        // Single-headed households: derived from members_present == 1 on a
        // checked-in family, never a stored tag.
        if ($request->boolean('single_headed')) {
            $query->singleHeaded();
        }

        $households = $query->latest()->paginate(20)->withQueryString();
        $barangays = Barangay::orderBy('name')->get();
        $shelters = EvacuationCenter::orderBy('name')->get();
        $classifications = VulnerableClassification::selectable()->orderBy('name')->get();
        $ageGroups = AgeTier::options();

        return view('cityadmin.evacuees.index', compact(
            'households', 'barangays', 'shelters', 'classifications', 'ageGroups'
        ));
    }

    /** City Admin can register into ANY shelter (extra shelter-selector field). */
    /**
     * One household as JSON, for the read-only view modal (Phase 6 item 10).
     *
     * City-wide: unlike the per-shelter endpoint on ShelterDetailController,
     * this takes no centre, because Evacuee Profiling lists households across
     * every shelter and some have none at all. No ownership check is needed --
     * city_admin has city-wide read access by definition, and the route already
     * sits behind the role middleware.
     *
     * READ ONLY. It deliberately does not mirror the barangay endpoint's
     * edit-oriented extras (age_tier_fallback, split name parts): nothing on
     * this screen edits a household, and shipping fields no consumer reads is
     * how a payload drifts into a contract nobody remembers agreeing to.
     */
    public function show(Household $household)
    {
        $household->load(['members.vulnerableClassifications', 'headMember', 'originBarangay', 'evacuationCenter']);

        return response()->json([
            'id' => $household->id,
            'code' => $household->household_code,
            'address' => $household->origin_address,
            'origin_barangay' => $household->originBarangay?->name,
            'status' => $household->status,
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
                'birthdate' => $m->birthdate?->format('Y-m-d'),
                'sex' => $m->sex,
                'is_head' => (bool) $m->is_household_head,
                'is_present' => (bool) $m->is_present,
                'age_tier' => $m->ageTier(),
                'age_tier_label' => $m->ageTierShortLabel(),
                // Retired classifications are filtered out here for the same
                // reason the edit forms filter them: Senior Citizen and Infant
                // are age tiers, and printing them as categories would put the
                // same person in two columns of the IDP form.
                'tags' => $m->vulnerableClassifications
                    ->where('is_selectable', true)
                    ->values()
                    ->map(fn ($c) => ['id' => $c->id, 'code' => $c->code, 'name' => $c->name])
                    ->values(),
            ])->values(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'origin_barangay_id' => ['required', 'exists:barangays,id'],
            /* PHASE 9 ITEM 4 -- pre-registration.

               This was ['required', ...], and then the chosen shelter was
               DISCARDED unless checkin was true: City Admin was forced to pick a
               shelter and the pick was thrown away. The barangay side has never
               required it -- Barangay\EvacueeProfilingController::validateHousehold()
               has no rule for this field at all -- so a family could be
               pre-registered from one screen and not the other. Gotcha 19 again.

               required_if rather than nullable alone: a shelter is genuinely
               needed when the family is being checked in on the same submit, and
               the two buttons post checkin=0 and checkin=1 respectively. */
            'evacuation_center_id' => ['nullable', 'required_if:checkin,1', 'exists:evacuation_centers,id'],
            'address' => ['required', 'string', 'max:255'],
            'checkin' => ['nullable', 'boolean'],
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
            'members.*.is_present' => ['nullable'],
            // PHASE 7 ITEM 2 -- rejects Pregnant Woman / Lactating Mother on a
            // member whose sex is not female. Needs the request because the
            // rule reads the sibling sex field on the same member row.
            'members.*.tags' => MemberRules::tags($request),
            'members.*.tags.*' => ['integer', 'exists:vulnerable_classifications,id'],
        ], [
            'evacuation_center_id.required_if' => 'Choose a shelter to check this family in to, '
                . 'or use Save to register them without one.',
            'members.*.age_group.required_without' => 'Choose an age group for any member without a date of birth.',
            'members.*.sex.required' => 'Sex is required for every member.',
            'members.*.birthdate.before_or_equal' => 'A date of birth cannot be in the future.',
            'members.*.birthdate.after_or_equal' => 'Check the date of birth -- nobody in the system can be older than '
                . MemberRules::MAX_AGE_YEARS . ' years.',
        ]);

        $checkin = $request->boolean('checkin');

        // PHASE 9 ITEM 4. Only resolved when it is going to be used. A
        // pre-registration leaves evacuation_center_id null and status
        // 'registered', which is exactly what the barangay side already
        // produces, and what the check-in picker already looks for -- both
        // search endpoints match unassigned households.
        $center = $checkin ? EvacuationCenter::findOrFail($data['evacuation_center_id']) : null;

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
                // Derived, not incremented -- and never flips the shelter to
                // 'full'/inactive: an overcapacity shelter stays active so it can
                // keep accepting and tracking evacuees.
                $center->recalcOccupancy();
            }

            return $household;
        }));

        AuditLogger::log('created', $household, "City Admin registered household {$household->household_code}");

        return redirect()->route('city.evacuees.index')
            ->with('success', "Household {$household->household_code} registered" . ($checkin ? ' and checked in.' : '.'));
    }
}
