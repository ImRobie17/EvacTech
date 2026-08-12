<?php

namespace App\Http\Controllers\Barangay;

use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\ReliefInventory;
use App\Support\AgeTier;
use App\Support\IdpForm;
use Illuminate\Support\Carbon;

class DashboardController extends BarangayController
{
    public function index()
    {
        $center = $this->center();

        $kpis = [
            'individuals' => 0,
            'households' => 0,
            'capacity_pct' => null,
            'capacity' => 0,
            'occupancy' => 0,
            'over_by' => 0,
            'band' => 'unknown',
            'relief_packs' => 0,
            'low_stock' => false,
            'vulnerable' => 0,
            'single_headed' => 0,
        ];
        $ageMatrix = AgeTier::emptySexMatrix(includeUnknown: true);
        $ageRows = [];
        $categoryRows = [];
        $chart = ['labels' => [], 'data' => []];
        $recent = collect();

        if ($center) {
            $checkedIn = Household::where('evacuation_center_id', $center->id)
                ->where('status', 'checked_in');

            $kpis['households'] = (clone $checkedIn)->count();
            $kpis['individuals'] = (clone $checkedIn)->sum('members_present');
            $kpis['capacity'] = $center->capacity;
            $kpis['occupancy'] = $center->current_occupancy;
            $kpis['capacity_pct'] = $center->capacity > 0
                ? round(($center->current_occupancy / $center->capacity) * 100)
                : null;
            // Overcapacity is reported, never used to deactivate the shelter.
            $kpis['over_by'] = $center->overBy();
            $kpis['band'] = $center->capacityBand();

            $kpis['relief_packs'] = ReliefInventory::where('evacuation_center_id', $center->id)->sum('quantity_on_hand');
            $kpis['low_stock'] = ReliefInventory::where('evacuation_center_id', $center->id)
                ->whereColumn('quantity_on_hand', '<=', 'reorder_level')
                ->where('reorder_level', '>', 0)
                ->exists();

            // Phase 2 item 7. This tile used to read high for the wrong reason:
            // manual tags never saved (the members[0][tags[]] name bug), so the
            // only rows in the pivot were the Senior / Infant tags the server
            // stamped on automatically. Those are age tiers now, so the count is
            // restricted to SELECTABLE classifications and will drop sharply on
            // existing data. That is the bug being fixed, not a regression --
            // seniors and infants are reported in the age-tier breakdown below.
            $kpis['vulnerable'] = HouseholdMember::where('is_present', true)
                ->whereHas('household', fn ($q) => $q
                    ->where('evacuation_center_id', $center->id)
                    ->where('status', 'checked_in'))
                ->whereHas('vulnerableClassifications', fn ($q) => $q
                    ->where('vulnerable_classifications.is_selectable', true))
                ->count();

            // Single-headed households: derived from members_present == 1 on a
            // checked-in family. Never a stored tag.
            // PHASE 10A. Fourth hand copy of scopeSingleHeaded(); see the note
            // on the City Admin dashboard. A separated individual sheltering
            // alone here is a fragment of a family elsewhere, not a
            // single-headed household.
            $kpis['single_headed'] = (clone $checkedIn)
                ->where('members_present', 1)
                ->whereNull('separated_from_household_id')
                ->count();

            // Age-tier breakdown, bucketed in SQL and grouped by sex so the same
            // query shape feeds the Phase 3 IDP Monitoring Form.
            // Grouped inside AgeTier via a derived table -- grouping directly by
            // the CASE expression trips MySQL's ONLY_FULL_GROUP_BY (error 1055).
            $ageMatrix = AgeTier::sexMatrixFor(
                HouseholdMember::query()
                    ->where('household_members.is_present', true)
                    ->whereHas('household', fn ($q) => $q
                        ->where('evacuation_center_id', $center->id)
                        ->where('status', 'checked_in')),
                includeUnknown: true
            );

            // Phase 3 item 9. Vulnerable-category chart. Built by IdpForm, not
            // by a query written here, so the chart and the signed CSWDO form
            // are the same figures by construction. Chronic Illness is off this
            // chart because it is off that form.
            $ageRows = AgeTier::chartRows($ageMatrix);
            $categoryRows = IdpForm::categoriesFor($center);

            // Daily registrations, past 7 days.
            // RESCOPED: was origin_barangay_id = auth()->user()->barangay_id. Staff
            // are no longer tied to a barangay, so this now counts households
            // registered AT THIS SHELTER, which is what the operator cares about.
            $from = Carbon::today()->subDays(6);
            $counts = Household::where('evacuation_center_id', $center->id)
                ->where('created_at', '>=', $from)
                ->get()
                ->groupBy(fn ($h) => $h->created_at->format('Y-m-d'))
                ->map->count();

            for ($d = 0; $d < 7; $d++) {
                $day = $from->copy()->addDays($d);
                $chart['labels'][] = $day->format('D');
                $chart['data'][] = $counts[$day->format('Y-m-d')] ?? 0;
            }

            // Last 5 check-in / check-out events
            $recent = Household::with('headMember')
                ->where('evacuation_center_id', $center->id)
                ->where(fn ($q) => $q->whereNotNull('checked_in_at')->orWhereNotNull('checked_out_at'))
                ->get()
                ->flatMap(function ($h) {
                    $events = [];
                    if ($h->checked_in_at) {
                        $events[] = ['type' => 'check_in', 'household' => $h, 'at' => $h->checked_in_at];
                    }
                    if ($h->checked_out_at) {
                        $events[] = ['type' => 'check_out', 'household' => $h, 'at' => $h->checked_out_at];
                    }

                    return $events;
                })
                ->sortByDesc('at')
                ->take(5);
        }

        return view('barangay.dashboard', compact('center', 'kpis', 'chart', 'recent', 'ageMatrix', 'ageRows', 'categoryRows'));
    }
}
