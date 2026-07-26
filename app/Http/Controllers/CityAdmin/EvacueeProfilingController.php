<?php

namespace App\Http\Controllers\CityAdmin;

use App\Http\Controllers\Controller;
use App\Models\Barangay;
use App\Models\EvacuationCenter;
use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\VulnerableClassification;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class EvacueeProfilingController extends Controller
{
    public function index(Request $request)
    {
        // City-wide: every household in every shelter, filterable.
        $query = Household::with(['headMember', 'originBarangay', 'evacuationCenter', 'members.vulnerableClassifications']);

        if ($search = trim((string) $request->input('q'))) {
            $query->whereHas('members', fn ($q) => $q
                ->where('is_household_head', true)
                ->where('full_name', 'like', "%{$search}%"));
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

        $households = $query->latest()->paginate(20)->withQueryString();
        $barangays = Barangay::orderBy('name')->get();
        $shelters = EvacuationCenter::orderBy('name')->get();
        $classifications = VulnerableClassification::orderBy('name')->get();

        return view('cityadmin.evacuees.index', compact('households', 'barangays', 'shelters', 'classifications'));
    }

    /** City Admin can register into ANY shelter (extra shelter-selector field). */
    public function store(Request $request)
    {
        $data = $request->validate([
            'origin_barangay_id' => ['required', 'exists:barangays,id'],
            'evacuation_center_id' => ['required', 'exists:evacuation_centers,id'],
            'address' => ['required', 'string', 'max:255'],
            'checkin' => ['nullable', 'boolean'],
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

        $checkin = $request->boolean('checkin');
        $center = EvacuationCenter::findOrFail($data['evacuation_center_id']);

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
                // Derived, not incremented -- and never flips the shelter to
                // 'full'/inactive: an overcapacity shelter stays active so it can
                // keep accepting and tracking evacuees.
                $center->recalcOccupancy();
            }

            return $household;
        });

        AuditLogger::log('created', $household, "City Admin registered household {$household->household_code}");

        return redirect()->route('city.evacuees.index')
            ->with('success', "Household {$household->household_code} registered" . ($checkin ? ' and checked in.' : '.'));
    }

    private function syncMembers(Household $household, array $members, bool $checkin): void
    {
        $senior = VulnerableClassification::where('name', 'like', 'Senior%')->first();
        $infant = VulnerableClassification::where('name', 'like', 'Infant%')->first();
        $headSet = false;
        $keptIds = [];

        foreach ($members as $i => $m) {
            $isHead = ! $headSet && ! empty($m['is_head']);
            if ($i === 0 && ! collect($members)->contains(fn ($x) => ! empty($x['is_head']))) {
                $isHead = true;
            }
            if ($isHead) {
                $headSet = true;
            }

            $fullName = trim($m['last_name'] . ', ' . $m['first_name'] . ' ' . ($m['middle_name'] ?? ''));
            $birthdate = Carbon::parse($m['birthdate']);
            $age = (int) $birthdate->age;

            $member = $household->members()->create([
                'full_name' => $fullName,
                'birthdate' => $birthdate,
                'age' => $age,
                'sex' => $m['sex'],
                'is_household_head' => $isHead,
                'family_role' => $isHead ? 'head' : 'member',
                'is_present' => $checkin ? (array_key_exists('is_present', $m) ? ! empty($m['is_present']) : true) : false,
            ]);
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
    }

    private function nextCode(): string
    {
        $year = now()->year;
        $count = Household::whereYear('created_at', $year)->count();
        return sprintf('HH-%d-%05d', $year, $count + 1);
    }
}
