@extends('layouts.superadmin')

@section('title', 'Dashboard')
@section('page-title', 'System Overview')
@section('page-subtitle', 'Health and activity across the entire EvacTech system.')

@section('content')
@if($system['maintenance'])
    <div class="alert alert-warning" role="alert"><strong>Maintenance mode is ON.</strong> Only Super Admins can access the system right now.</div>
@endif

<section class="kpi-grid" aria-label="System metrics">
    <article class="card kpi-card">
        <div class="kpi-head"><span class="kpi-title">Total Users</span></div>
        <p class="kpi-value" data-numeric>{{ number_format($stats['total_users']) }}</p>
        <p class="kpi-note">{{ $stats['active_users'] }} active</p>
    </article>
    <article class="card kpi-card">
        <div class="kpi-head"><span class="kpi-title">Logins Today</span></div>
        <p class="kpi-value" data-numeric>{{ number_format($stats['logins_today']) }}</p>
        <p class="kpi-note">Users who signed in today</p>
    </article>
    <article class="card kpi-card">
        <div class="kpi-head"><span class="kpi-title">Shelters</span></div>
        <p class="kpi-value" data-numeric>{{ number_format($stats['total_shelters']) }}</p>
        <p class="kpi-note">{{ number_format($stats['total_households']) }} households on record</p>
    </article>
    <article class="card kpi-card">
        <div class="kpi-head"><span class="kpi-title">Open Alerts</span></div>
        <p class="kpi-value {{ $stats['unresolved_alerts'] > 0 ? 'cap-full' : '' }}" data-numeric>{{ number_format($stats['unresolved_alerts']) }}</p>
        <p class="kpi-note">Unresolved system alerts</p>
    </article>
    <article class="card kpi-card">
        <div class="kpi-head"><span class="kpi-title">Database Size</span></div>
        <p class="kpi-value" data-numeric>{{ $system['db_size'] !== null ? $system['db_size'] . ' MB' : '—' }}</p>
        <p class="kpi-note">Last backup: {{ $system['last_backup']?->diffForHumans() ?? 'never' }}</p>
    </article>
</section>

<section class="dash-columns">
    <div>
        <article class="card panel">
            <h2 class="panel-title">Users by Role</h2>
            <table class="data-table">
                <thead><tr><th scope="col">Role</th><th scope="col">Accounts</th></tr></thead>
                <tbody>
                    @foreach($usersByRole as $r)
                        <tr><td>{{ $r->display_name }}</td><td data-numeric>{{ $r->total }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </article>

        <article class="card panel">
            <h2 class="panel-title">Recent Audit Logs</h2>
            <div class="activity-panel">
                @forelse($recentAudit as $log)
                    <div class="activity-row">
                        <span class="badge badge-info">{{ ucfirst($log->action) }}</span>
                        <span class="activity-name">{{ $log->description ?? '—' }}</span>
                        <span class="text-muted">{{ $log->user?->name ?? 'System' }}</span>
                        <time class="activity-time">{{ $log->created_at?->diffForHumans() }}</time>
                    </div>
                @empty
                    <p class="empty-note">No audit activity yet.</p>
                @endforelse
            </div>
        </article>
    </div>

    <div class="dash-side">
        <article class="card panel">
            <h2 class="panel-title">System Status</h2>
            <ul class="status-list">
                <li><span>Environment</span><strong>{{ $system['app_env'] }}</strong></li>
                <li><span>PHP</span><strong data-numeric>{{ $system['php_version'] }}</strong></li>
                <li><span>Laravel</span><strong data-numeric>{{ $system['laravel_version'] }}</strong></li>
                <li><span>Maintenance</span><strong class="{{ $system['maintenance'] ? 'cap-full' : 'cap-ok' }}">{{ $system['maintenance'] ? 'ON' : 'OFF' }}</strong></li>
            </ul>
        </article>

        <article class="card panel">
            <h2 class="panel-title">Recent Logins</h2>
            <div class="activity-panel">
                @forelse($recentLogins as $u)
                    <div class="activity-row">
                        <span class="activity-name">{{ $u->name }} <small class="text-muted">({{ $u->role?->display_name }})</small></span>
                        <time class="activity-time">{{ $u->last_login_at?->diffForHumans() }}</time>
                    </div>
                @empty
                    <p class="empty-note">No logins recorded.</p>
                @endforelse
            </div>
        </article>

        <article class="card panel">
            <h2 class="panel-title">Recent System Actions</h2>
            <div class="activity-panel">
                @forelse($recentEvents as $e)
                    <div class="activity-row">
                        <span class="badge badge-info">{{ ucfirst(str_replace('_', ' ', $e->type)) }}</span>
                        <span class="activity-name">{{ $e->description }}</span>
                        <time class="activity-time">{{ $e->created_at?->diffForHumans() }}</time>
                    </div>
                @empty
                    <p class="empty-note">No system actions logged yet.</p>
                @endforelse
            </div>
        </article>
    </div>
</section>
@endsection
