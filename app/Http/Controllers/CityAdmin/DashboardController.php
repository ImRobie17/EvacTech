<?php

namespace App\Http\Controllers\CityAdmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Barangay;
use App\Models\EvacuationCenter;
use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\ReliefGood;
use App\Models\ReliefInventory;
use App\Models\ReliefTransaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index()
    {
        // ---- KPI cards (city-wide) ----
        $kpis = [
            'total_evacuees' => Household::where('status', 'checked_in')->sum('members_present'),
            'active_shelters' => EvacuationCenter::where('status', 'active')->count(),
            'total_shelters' => EvacuationCenter::count(),
            'families_registered' => Household::count(),
            'families_today' => Household::whereDate('created_at', Carbon::today())->count(),
            'relief_distributed' => ReliefTransaction::where('type', 'distributed')->sum('quantity'),
            'vulnerable' => HouseholdMember::where('is_present', true)
                ->whereHas('household', fn ($q) => $q->where('status', 'checked_in'))
                ->whereHas('vulnerabilities')
                ->count(),
        ];

        // ---- Top 5 barangays by checked-in evacuees ----
        $topBarangays = Household::query()
            ->where('households.status', 'checked_in')
            ->join('barangays', 'households.origin_barangay_id', '=', 'barangays.id')
            ->groupBy('barangays.id', 'barangays.name')
            ->selectRaw('barangays.name, SUM(households.members_present) as total')
            ->orderByDesc('total')
            ->limit(5)
            ->get();

        // ---- Vulnerable groups distribution (donut) ----
        $vulnerableGroups = DB::table('member_vulnerabilities')
            ->join('vulnerable_classifications', 'member_vulnerabilities.vulnerable_classification_id', '=', 'vulnerable_classifications.id')
            ->join('household_members', 'member_vulnerabilities.household_member_id', '=', 'household_members.id')
            ->where('household_members.is_present', true)
            ->groupBy('vulnerable_classifications.id', 'vulnerable_classifications.name')
            ->selectRaw('vulnerable_classifications.name, COUNT(DISTINCT household_members.id) as total')
            ->orderByDesc('total')
            ->get();

        // ---- Heat map 1: Disaster risk by barangay (from barangays.risk_level) ----
        $riskHeatmap = Barangay::orderBy('name')->get()->map(fn ($b) => [
            'name' => $b->name,
            'risk' => $b->risk_level, // low | moderate | high
        ]);

        // ---- Heat map 2: Shelter status / occupancy tier (inactive grayed) ----
        $shelterHeatmap = EvacuationCenter::with('barangay')->orderBy('name')->get()->map(function ($c) {
            $pct = $c->capacity > 0 ? round($c->current_occupancy / $c->capacity * 100) : null;
            $tier = $c->status !== 'active' ? 'inactive'
                : ($pct === null ? 'unknown'
                : ($pct > 100 ? 'over' : ($pct >= 90 ? 'full' : ($pct >= 70 ? 'warn' : 'ok'))));
            return [
                'name' => $c->name,
                'barangay' => $c->barangay?->name,
                'status' => $c->status,
                'pct' => $pct,
                'occupancy' => $c->current_occupancy,
                'capacity' => $c->capacity,
                'tier' => $tier,
            ];
        });

        // ---- Heat map 3: Relief stock per shelter (per good, low/med/high) ----
        $goods = ReliefGood::orderBy('name')->get();
        $centers = EvacuationCenter::orderBy('name')->get();
        $inventoryLookup = ReliefInventory::get()
            ->groupBy('evacuation_center_id')
            ->map(fn ($rows) => $rows->keyBy('relief_good_id'));

        $reliefHeatmap = $centers->map(function ($c) use ($goods, $inventoryLookup) {
            $cells = $goods->map(function ($g) use ($c, $inventoryLookup) {
                $inv = optional($inventoryLookup->get($c->id))->get($g->id);
                $qty = $inv?->quantity_on_hand ?? 0;
                $reorder = $inv?->reorder_level ?? 0;
                $tier = $qty <= 0 ? 'none'
                    : ($reorder > 0 && $qty <= $reorder ? 'low'
                    : ($reorder > 0 && $qty <= $reorder * 2 ? 'med' : 'high'));
                return ['good' => $g->name, 'qty' => $qty, 'tier' => $tier];
            });
            return ['center' => $c->name, 'cells' => $cells];
        });

        // ---- Recent activity (last 6 audit entries, city-wide) ----
        $recent = AuditLog::with('user')->latest('created_at')->limit(6)->get();

        return view('cityadmin.dashboard', compact(
            'kpis', 'topBarangays', 'vulnerableGroups',
            'riskHeatmap', 'shelterHeatmap', 'reliefHeatmap', 'goods', 'recent'
        ));
    }
}
