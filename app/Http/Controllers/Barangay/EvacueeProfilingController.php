<?php

namespace App\Http\Controllers\Barangay;

use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\VulnerableClassification;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class EvacueeProfilingController extends BarangayController
{
    public function index(Request $request)
    {
        $center = $this->center();

        $query = Household::with(['headMember', 'originBarangay', 'evacuationCenter', 'members.vulnerableClassifications'])
            ->where('origin_barangay_id', auth()->user()->barangay_id);

        if ($search = trim((string) $request->input('q'))) {
            $query->whereHas('members', fn ($q) => $q
                ->where('is_household_head', true)
                ->where('full_name', 'like', "%{$search}%"));
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($vuln = $request->input('vulnerable')) {
            $query->whereHas('members.vulnerableClassifications', fn ($q) => $q
                ->where('vulnerable_classifications.id', $vuln));
        }

        $households = $query->latest()->paginate(15)->withQueryString();
        $classifications = VulnerableClassification::orderBy('name')->get();

        return view('barangay.evacuees.index', compact('households', 'classifications', 'center'));
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
                'origin_barangay_id' => auth()->user()->barangay_id,
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
                $center->increment('current_occupancy', $present);
                $this->refreshCenterStatus($center);
            }

            return $household;
        });

        AuditLogger::log('created', $household, "Registered household {$household->household_code}" . ($checkin ? ' and checked in' : ''));

        return redirect()->route('barangay.evacuees.index')
            ->with('success', "Household {$household->household_code} registered" . ($checkin ? ' and checked in.' : '.'));
    }

    /** Load one household with members + tags (JSON, used by Edit Family Group / Check-in modals). */
    public function show(Household $household)
    {
        $this->authorizeBarangay($household);

        $household->load(['members.vulnerableClassifications', 'headMember', 'evacuationCenter']);

        return response()->json([
            'id' => $household->id,
            'code' => $household->household_code,
            'address' => $household->origin_address,
            'status' => $household->status,
            'center' => $household->evacuationCenter?->name,
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
        $this->authorizeBarangay($household);
        $data = $this->validateHousehold($request);

        DB::transaction(function () use ($household, $data) {
            $household->update(['origin_address' => $data['address']]);
            $this->syncMembers($household, $data['members'], keepPresence: true);
            $household->update(['number_of_members' => $household->members()->count()]);
        });

        AuditLogger::log('updated', $household, "Updated family group {$household->household_code}");

        return redirect()->back()->with('success', 'Family group updated.');
    }

    /** Remove household (soft business rule: only when not currently checked in). */
    public function destroy(Household $household)
    {
        $this->authorizeBarangay($household);

        if ($household->status === 'checked_in') {
            return back()->withErrors(['household' => 'Check the household out before removing it.']);
        }

        $code = $household->household_code;
        $household->delete();

        AuditLogger::log('deleted', $household, "Removed household {$code}");

        return back()->with('success', "Household {$code} removed.");
    }

    /** JSON search by head name (used by Check-in Family + Distribute Relief). */
    public function search(Request $request)
    {
        $term = trim((string) $request->input('q'));

        $results = Household::with(['headMember', 'evacuationCenter'])
            ->where('origin_barangay_id', auth()->user()->barangay_id)
            ->when($term, fn ($q) => $q->whereHas('members', fn ($m) => $m
                ->where('is_household_head', true)
                ->where('full_name', 'like', "%{$term}%")))
            ->limit(10)
            ->get()
            ->map(fn ($h) => [
                'id' => $h->id,
                'code' => $h->household_code,
                'head' => $h->headMember?->full_name ?? '—',
                'size' => $h->number_of_members,
                'status' => $h->status,
                'center' => $h->evacuationCenter?->name,
            ]);

        return response()->json($results);
    }

    // ---------------------------------------------------------------

    private function validateHousehold(Request $request): array
    {
        return $request->validate([
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
        ]);
    }

    /**
     * Create/update members, mark exactly one head, apply manual tags,
     * and AUTO-TAG Senior (60+) / Infant-Young Child (0-5) from birthdate.
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
                $attrs['is_present'] = ! empty($m['is_present']);
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

            // Manual tags + auto age tags (never removing manual ones we didn't set)
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

        // Members removed in the edit form
        $household->members()->whereNotIn('id', $keptIds)->delete();
    }

    private function nextCode(): string
    {
        $year = now()->year;
        $count = Household::whereYear('created_at', $year)->count();
        return sprintf('HH-%d-%05d', $year, $count + 1);
    }

    private function authorizeBarangay(Household $household): void
    {
        abort_if($household->origin_barangay_id !== auth()->user()->barangay_id, 403);
    }

    protected function refreshCenterStatus($center): void
    {
        if ($center->capacity > 0 && $center->current_occupancy >= $center->capacity) {
            $center->update(['status' => 'full']);
        } elseif ($center->status === 'full') {
            $center->update(['status' => 'active']);
        }
    }
}
