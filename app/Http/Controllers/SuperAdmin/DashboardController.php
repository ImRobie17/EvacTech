<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\EvacuationCenter;
use App\Models\Household;
use App\Models\Role;
use App\Models\SystemAlert;
use App\Models\SystemEvent;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index()
    {
        $usersByRole = User::selectRaw('roles.display_name, roles.name, COUNT(users.id) as total')
            ->join('roles', 'users.role_id', '=', 'roles.id')
            ->groupBy('roles.display_name', 'roles.name')
            ->get();

        $stats = [
            'total_users' => User::count(),
            'active_users' => User::where('status', 'active')->count(),
            'logins_today' => User::whereDate('last_login_at', Carbon::today())->count(),
            'total_households' => Household::count(),
            'total_shelters' => EvacuationCenter::count(),
            'unresolved_alerts' => SystemAlert::where('is_resolved', false)->count(),
        ];

        $recentLogins = User::with('role')
            ->whereNotNull('last_login_at')
            ->orderByDesc('last_login_at')
            ->limit(6)
            ->get();

        $recentAudit = AuditLog::with('user')
            ->latest('created_at')
            ->limit(8)
            ->get();

        $recentEvents = SystemEvent::with('performedBy')
            ->orderByDesc('created_at')
            ->limit(6)
            ->get();

        $recentAlerts = SystemAlert::latest()->limit(5)->get();

        $system = [
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'maintenance' => (bool) cache()->get('evactech_maintenance', false),
            'db_size' => $this->databaseSizeMb(),
            'last_backup' => SystemEvent::where('type', 'backup')->latest('created_at')->first()?->created_at,
            'app_env' => config('app.env'),
        ];

        return view('superadmin.dashboard', compact(
            'usersByRole', 'stats', 'recentLogins', 'recentAudit', 'recentEvents', 'recentAlerts', 'system'
        ));
    }

    private function databaseSizeMb(): ?float
    {
        try {
            $db = config('database.connections.' . config('database.default') . '.database');
            $row = DB::selectOne(
                'SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS mb
                 FROM information_schema.tables WHERE table_schema = ?',
                [$db]
            );
            return $row?->mb !== null ? (float) $row->mb : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
