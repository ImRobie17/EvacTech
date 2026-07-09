<?php

namespace App\Http\Controllers\Barangay;

use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\ReliefInventory;
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
            'relief_packs' => 0,
            'low_stock' => false,
            'vulnerable' => 0,
        ];
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

            $kpis['relief_packs'] = ReliefInventory::where('evacuation_center_id', $center->id)->sum('quantity_on_hand');
            $kpis['low_stock'] = ReliefInventory::where('evacuation_center_id', $center->id)
                ->whereColumn('quantity_on_hand', '<=', 'reorder_level')
                ->where('reorder_level', '>', 0)
                ->exists();

            $kpis['vulnerable'] = HouseholdMember::where('is_present', true)
                ->whereHas('household', fn ($q) => $q
                    ->where('evacuation_center_id', $center->id)
                    ->where('status', 'checked_in'))
                ->whereHas('vulnerabilities')
                ->count();

            // Daily registrations, past 7 days
            $from = Carbon::today()->subDays(6);
            $counts = Household::where('origin_barangay_id', auth()->user()->barangay_id)
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

        return view('barangay.dashboard', compact('center', 'kpis', 'chart', 'recent'));
    }
}
