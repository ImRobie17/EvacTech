@extends('layouts.superadmin')

@section('title', 'Dashboard')
@section('page-title', 'System Overview')
@section('page-subtitle', 'Health and activity across the entire EvacTech system.')

@section('content')
@if($system['maintenance'])
    <div class="alert alert-warning" role="alert"><strong>Maintenance mode is ON.</strong> Only Super Admins can access the system right now.</div>
@endif

{{-- Five KPI cards, same responsive ladder as the other two dashboards: one
     column on a phone, two from 640px, three from 1024px, five above 1280px. --}}
<section class="mb-4 grid grid-cols-1 items-stretch gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5" aria-label="System metrics">
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
        {{-- An entity cannot go inside {{ }}: Blade's e() double-encodes it and
             "&mdash;" would render as literal text. The branch keeps the entity
             in raw HTML, where the parser decodes it. --}}
        <p class="kpi-value" data-numeric>@if($system['db_size'] !== null) {{ $system['db_size'] }} MB @else &mdash; @endif</p>
        <p class="kpi-note">Last backup: {{ $system['last_backup']?->diffForHumans() ?? 'never' }}</p>
    </article>
</section>

<section class="grid grid-cols-1 items-start gap-4 lg:grid-cols-3">
    <div class="flex flex-col gap-4 lg:col-span-2">
        <article class="card panel">
            <h2 class="panel-title">Users by Role</h2>
            {{-- DELIBERATELY NOT data-stack. Two columns already fit a 380px
                 screen without scrolling, and stacking would turn each role into
                 a card containing one number -- more vertical space, no gain.
                 Matches how the barangay dashboard's figures table was left. --}}
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
                    {{-- Wraps rather than overflowing at 380px: action,
                         description, user and time are all needed. --}}
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-border p-3 text-sm last:border-b-0">
                        <span class="badge badge-info">{{ ucfirst($log->action) }}</span>
                        <span class="min-w-0 flex-1 font-medium">{{ $log->description ?? '-' }}</span>
                        <span class="text-ink-muted">{{ $log->user?->name ?? 'System' }}</span>
                        <time class="text-ink-muted">{{ $log->created_at?->diffForHumans() }}</time>
                    </div>
                @empty
                    <p class="empty-note">No audit activity yet.</p>
                @endforelse
            </div>
        </article>
    </div>

    <div class="flex flex-col gap-4">
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
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-border p-3 text-sm last:border-b-0">
                        <span class="min-w-0 flex-1 font-medium">{{ $u->name }} <small class="text-ink-muted">({{ $u->role?->display_name }})</small></span>
                        <time class="text-ink-muted">{{ $u->last_login_at?->diffForHumans() }}</time>
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
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-border p-3 text-sm last:border-b-0">
                        <span class="badge badge-info">{{ ucfirst(str_replace('_', ' ', $e->type)) }}</span>
                        <span class="min-w-0 flex-1 font-medium">{{ $e->description }}</span>
                        <time class="text-ink-muted">{{ $e->created_at?->diffForHumans() }}</time>
                    </div>
                @empty
                    <p class="empty-note">No system actions logged yet.</p>
                @endforelse
            </div>
        </article>
    </div>
</section>
@endsection
