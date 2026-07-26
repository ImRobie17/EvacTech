<?php

namespace App\Http\Controllers\Barangay;

use App\Models\Barangay;
use App\Models\EvacuationCenter;
use App\Models\Household;
use App\Models\VulnerableClassification;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class EvacueeProfilingController extends BarangayController
{
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

        if ($vuln = $request->input('vulnerable')) {
            $query->whereHas('members.vulnerableClassifications', fn ($q) => $q
                ->where('vulnerable_classifications.id', $vuln));
        }

        $households = $query->latest()->paginate(15)->withQueryString();
        $classifications = VulnerableClassification::orderBy('name')->get();

        // Origin barangay is now an explicit field on the Add Evacuee form:
        // staff are no longer tied to a barangay, so it can no longer be
        // inferred from the account.
        $barangays = Barangay::orderBy('name')->get();
        $defaultBarangayId = $center?->barangay_id;

        return view('barangay.evacuees.index', compact('households', 'classifications', 'center', 'barangays', 'defaultBarangayId'));
    }

    /** Register a new household (Add Evacuee modal). checkin=1 also checks them in. */
    public function store(Request $request)
    {
        $data = $this->validateHousehold($request);
        $checkin = $request->boolean('checkin');
        $center = $checkin ? $this->centerOrFail() : $this->center();

        $household = DB::transaction(function () use ($data, $checkin, $center) {
            $household = Household::create([
                'household_code' => $this->nextCode(),
                'origin_barangay_id' => $data['origin_barangay_id'],
                'origin_address' => $data['address'],
                'number_of_members' => count($data['members']),
                'status' => 'registered',
                'registered_by' => auth()->id(),
            ]);

            $this->syncMembers($household, $data['members'], $checkin);

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
        });

        AuditLogger::log('created', $household,
            "Registered household {$household->household_code}" . ($checkin ? " and checked in at {$center->name}" : ''));

        return redirect()->route('barangay.evacuees.index')
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
            'center' => $household->evacuationCenter?->name,
            'center_id' => $household->evacuation_center_id,
            'head_member_id' => $household->head_member_id,
            'members' => $household->members->map(fn ($m) => [
                'id' => $m->id,
                'full_name' => $m->full_name,
                'birthdate' => $m->birthdate?->format('Y-m-d'),
                'sex' => $m->sex,
                'is_head' => $m->is_household_head,
                'is_present' => $m->is_present,
                'tags' => $m->vulnerableClassifications->map(fn ($c) => ['id' => $c->id, 'name' => $c->name]),
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
            $this->syncMembers($household, $data['members'], keepPresence: true);
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

        // Households at any shelter on this staff member's roster, plus any not yet
        // placed in a shelter (so they can be checked in for the first time).
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
            'members.*.last_name' => ['required', 'string', 'max:100'],
            'members.*.first_name' => ['required', 'string', 'max:100'],
            'members.*.middle_name' => ['nullable', 'string', 'max:100'],
            'members.*.birthdate' => ['required', 'date', 'before_or_equal:today'],
            'members.*.sex' => ['required', 'in:male,female'],
            'members.*.is_head' => ['nullable'],
            'members.*.is_present' => ['nullable'],
            'members.*.tags' => ['nullable', 'array'],
            'members.*.tags.*' => ['integer', 'exists:vulnerable_classifications,id'],
        ], [
            'origin_barangay_id.required' => 'Select the barangay this family came from.',
        ]);
    }

    /**
     * Create/update members, mark exactly one head, apply manual tags,
     * and AUTO-TAG Senior (60+) / Infant-Young Child (0-5) from birthdate.
     *
     * NOTE: the age-tag rules here are replaced in Phase 2 (#5/#6) by the seven
     * age tiers and the revised category list. Left as-is deliberately so this
     * item changes shelter architecture only.
     */
    private function syncMembers(Household $household, array $members, bool $checkin = false, bool $keepPresence = false): void
    {
        $senior = VulnerableClassification::where('name', 'like', 'Senior%')->first();
        $infant = VulnerableClassification::where('name', 'like', 'Infant%')->first();

        $headSet = false;
        $keptIds = [];

        foreach ($members as $i => $m) {
            $isHead = ! $headSet && ! empty($m['is_head']);
            if ($i === 0 && ! collect($members)->contains(fn ($x) => ! empty($x['is_head']))) {
                $isHead = true; // default: first row is the head if none flagged
            }
            if ($isHead) {
                $headSet = true;
            }

            $fullName = trim($m['last_name'] . ', ' . $m['first_name'] . ' ' . ($m['middle_name'] ?? ''));
            $birthdate = Carbon::parse($m['birthdate']);
            $age = (int) $birthdate->age;

            $attrs = [
                'full_name' => $fullName,
                'birthdate' => $birthdate,
                'age' => $age,
                'sex' => $m['sex'],
                'is_household_head' => $isHead,
                'family_role' => $isHead ? 'head' : 'member',
            ];

            if ($checkin) {
                $attrs['is_present'] = array_key_exists('is_present', $m)
                    ? ! empty($m['is_present'])
                    : true;
            } elseif (! $keepPresence) {
                $attrs['is_present'] = false;
            }

            if (! empty($m['id'])) {
                $member = $household->members()->whereKey($m['id'])->first();
                $member?->update($attrs);
                $member ??= $household->members()->create($attrs);
            } else {
                $member = $household->members()->create($attrs);
            }
            $keptIds[] = $member->id;

            $tagIds = collect($m['tags'] ?? [])->map(fn ($t) => (int) $t);
            if ($age >= 60 && $senior) {
                $tagIds->push($senior->id);
            }
            if ($age <= 5 && $infant) {
                $tagIds->push($infant->id);
            }
            $member->vulnerableClassifications()->sync(
                $tagIds->unique()->mapWithKeys(fn ($id) => [$id => ['tagged_by' => auth()->id(), 'tagged_at' => now()]])->all()
            );

            if ($isHead) {
                $household->update(['head_member_id' => $member->id]);
            }
        }

        $household->members()->whereNotIn('id', $keptIds)->delete();
    }

    private function nextCode(): string
    {
        $year = now()->year;
        $count = Household::whereYear('created_at', $year)->count();

        return sprintf('HH-%d-%05d', $year, $count + 1);
    }
}
