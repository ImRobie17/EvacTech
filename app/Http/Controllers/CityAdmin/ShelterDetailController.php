<?php

namespace App\Http\Controllers\CityAdmin;

use App\Http\Controllers\Controller;
use App\Models\EvacuationCenter;
use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\ReliefGood;
use App\Models\ReliefInventory;
use App\Models\ReliefTransaction;
use App\Models\VulnerableClassification;
use App\Services\AuditLogger;
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
            'classifications' => VulnerableClassification::orderBy('name')->get(),
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
        $query = Household::with(['headMember', 'originBarangay'])
            ->where('evacuation_center_id', $center->id);

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

        return [
            'households' => $query->paginate(15)->withQueryString(),
            'originBarangays' => \App\Models\Barangay::orderBy('name')->get(),
        ];
    }

    /** Check a registered household into THIS shelter. */
    public function checkIn(Request $request, EvacuationCenter $center, Household $household)
    {
        $data = $request->validate([
            'present' => ['required', 'array', 'min:1'],
            'present.*' => ['integer'],
        ]);

        if ($household->status === 'checked_in' && $household->evacuation_center_id === $center->id) {
            return back()->withErrors(['household' => 'This household is already checked in here.']);
        }

        $previous = $household->evacuationCenter;

        DB::transaction(function () use ($household, $center, $previous, $data) {
            $household->members()->update(['is_present' => false]);
            $household->members()->whereIn('id', $data['present'])->update(['is_present' => true]);

            $household->update([
                'evacuation_center_id' => $center->id,
                'status' => 'checked_in',
                'checked_in_at' => now(),
                'checked_out_at' => null,
                'members_present' => $household->members()->where('is_present', true)->count(),
            ]);

            $center->recalcOccupancy();
            if ($previous && $previous->id !== $center->id) {
                $previous->recalcOccupancy();
            }
        });

        $household->refresh();

        AuditLogger::log('updated', $household,
            "City Admin checked in {$household->household_code} at {$center->name} ({$household->members_present} present)");

        return $this->backToTab($center, 'households', "Household {$household->household_code} checked in.");
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

        DB::transaction(function () use ($household, $center) {
            $household->members()->update(['is_present' => false]);
            $household->update([
                'status' => 'checked_out',
                'checked_out_at' => now(),
                'members_present' => 0,
            ]);
            $center->recalcOccupancy();
        });

        AuditLogger::log('updated', $household, "City Admin checked out {$household->household_code}");

        return $this->backToTab($center, 'households', "Household {$household->household_code} checked out.");
    }

    /** Household JSON for the inline Check-in and Edit Family Group modals. */
    public function household(EvacuationCenter $center, Household $household)
    {
        $household->load(['members.vulnerableClassifications', 'headMember', 'originBarangay']);

        return response()->json([
            'id' => $household->id,
            'code' => $household->household_code,
            'address' => $household->origin_address,
            'origin_barangay_id' => $household->origin_barangay_id,
            'origin_barangay' => $household->originBarangay?->name,
            'status' => $household->status,
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
                'tags' => $m->vulnerableClassifications->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->values(),
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
            ->when($term, fn ($q) => $q->whereHas('members', fn ($m) => $m
                ->where('is_household_head', true)
                ->where('full_name', 'like', "%{$term}%")))
            // Households already here and checked in are not check-in candidates.
            ->whereNot(fn ($q) => $q
                ->where('evacuation_center_id', $center->id)
                ->where('status', 'checked_in'))
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
                'origin_barangay' => $h->originBarangay?->name,
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
            'members.*.last_name' => ['required', 'string', 'max:100'],
            'members.*.first_name' => ['required', 'string', 'max:100'],
            'members.*.middle_name' => ['nullable', 'string', 'max:100'],
            'members.*.birthdate' => ['required', 'date', 'before_or_equal:today'],
            'members.*.sex' => ['required', 'in:male,female'],
            'members.*.is_head' => ['nullable'],
            'members.*.tags' => ['nullable', 'array'],
            'members.*.tags.*' => ['integer', 'exists:vulnerable_classifications,id'],
        ]);

        DB::transaction(function () use ($household, $center, $data) {
            $household->update([
                'origin_address' => $data['address'],
                'origin_barangay_id' => $data['origin_barangay_id'],
            ]);

            $senior = VulnerableClassification::where('name', 'like', 'Senior%')->first();
            $infant = VulnerableClassification::where('name', 'like', 'Infant%')->first();
            $keptIds = [];
            $headSet = false;

            foreach ($data['members'] as $i => $m) {
                $isHead = ! $headSet && ! empty($m['is_head']);
                if ($i === 0 && ! collect($data['members'])->contains(fn ($x) => ! empty($x['is_head']))) {
                    $isHead = true;
                }
                if ($isHead) {
                    $headSet = true;
                }

                $birthdate = Carbon::parse($m['birthdate']);
                $age = (int) $birthdate->age;

                $attrs = [
                    'full_name' => trim($m['last_name'] . ', ' . $m['first_name'] . ' ' . ($m['middle_name'] ?? '')),
                    'birthdate' => $birthdate,
                    'age' => $age,
                    'sex' => $m['sex'],
                    'is_household_head' => $isHead,
                    'family_role' => $isHead ? 'head' : 'member',
                ];

                $member = ! empty($m['id']) ? $household->members()->whereKey($m['id'])->first() : null;
                if ($member) {
                    $member->update($attrs);
                } else {
                    $member = $household->members()->create($attrs + ['is_present' => false]);
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
            $logQuery->whereHas('household.members', fn ($q) => $q
                ->where('is_household_head', true)
                ->where('full_name', 'like', "%{$search}%"));
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
            ->where('evacuation_center_id', $center->id)
            ->where('status', 'checked_in')
            ->when($term, fn ($q) => $q->whereHas('members', fn ($m) => $m
                ->where('is_household_head', true)
                ->where('full_name', 'like', "%{$term}%")))
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

    // -----------------------------------------------------------------

    private function backToTab(EvacuationCenter $center, string $tab, string $message)
    {
        return redirect()
            ->to(route('city.shelters.show', $center) . '?tab=' . $tab)
            ->with('success', $message);
    }
}
