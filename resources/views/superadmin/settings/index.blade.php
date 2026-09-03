@extends('layouts.superadmin')

@section('title', 'System Settings')
@section('page-title', 'System Settings')
@section('page-subtitle', 'Backups, maintenance mode, and system health.')

@section('content')
{{-- Controls take two thirds, the log panels one third, from 1024px. Below that
     everything stacks in source order, so the destructive maintenance-mode form
     is reached last rather than sitting beside it on a phone. --}}
<section class="grid grid-cols-1 items-start gap-4 lg:grid-cols-3">
    <div class="flex flex-col gap-4 lg:col-span-2">
        {{-- Health --}}
        <article class="card panel">
            <h2 class="panel-title">System Health</h2>
            <ul class="status-list">
                <li><span>Environment</span><strong>{{ $health['app_env'] }}</strong></li>
                <li><span>PHP Version</span><strong data-numeric>{{ $health['php_version'] }}</strong></li>
                <li><span>Laravel Version</span><strong data-numeric>{{ $health['laravel_version'] }}</strong></li>
                <li><span>Debug Mode</span><strong class="{{ $health['debug_mode'] ? 'cap-warn' : 'cap-ok' }}">{{ $health['debug_mode'] ? 'ON' : 'OFF' }}</strong></li>
                <li><span>Database Connection</span><strong class="{{ $health['db_connection'] ? 'cap-ok' : 'cap-full' }}">{{ $health['db_connection'] ? 'OK' : 'FAILED' }}</strong></li>
                <li><span>mysqldump available</span><strong class="{{ $health['mysqldump'] ? 'cap-ok' : 'cap-warn' }}">{{ $health['mysqldump'] ? 'Yes' : 'No' }}</strong></li>
                <li><span>Storage writable</span><strong class="{{ $health['storage_writable'] ? 'cap-ok' : 'cap-full' }}">{{ $health['storage_writable'] ? 'Yes' : 'No' }}</strong></li>
            </ul>
        </article>

        {{-- Database backup --}}
        <article class="card panel">
            <h2 class="panel-title">Database Backup</h2>
            <p class="kpi-note">Download a full SQL dump of the database. Requires <span class="font-mono">mysqldump</span> on the server.</p>
            @if(! $health['mysqldump'])
                <div class="alert alert-warning">mysqldump was not found on the server PATH, so backups are unavailable until it is installed.</div>
            @endif
            <form method="POST" action="{{ route('super.settings.backup') }}">
                @csrf
                <button type="submit" class="btn-primary" {{ $health['mysqldump'] ? '' : 'disabled' }}>&darr; Download Backup (.sql)</button>
            </form>
        </article>

        {{-- Maintenance mode --}}
        <article class="card panel">
            <h2 class="panel-title">Maintenance Mode</h2>
            @if($health['maintenance'])
                <div class="alert alert-warning"><strong>Maintenance mode is currently ON.</strong> Non-super-admin users are locked out.</div>
            @else
                <p class="kpi-note">Turning this on will log out and block all CSWD Office and Camp Manager users to prevent activity during maintenance. Super Admins keep access.</p>
            @endif
            <form method="POST" action="{{ route('super.settings.maintenance') }}" data-confirm="{{ $health['maintenance'] ? 'Turn OFF maintenance mode and restore access to everyone?' : 'Turn ON maintenance mode? All non-super-admin users will be logged out.' }}">
                @csrf
                <div class="field">
                    <label for="mm-confirm">Type <strong>maintenance</strong> to confirm</label>
                    <input type="text" id="mm-confirm" name="confirmation" autocomplete="off" required>
                </div>
                <button type="submit" class="{{ $health['maintenance'] ? 'btn-primary' : 'btn-danger' }}">
                    {{ $health['maintenance'] ? 'Turn OFF Maintenance Mode' : 'Turn ON Maintenance Mode' }}
                </button>
            </form>
        </article>
    </div>

    <div class="flex flex-col gap-4">
        {{-- System alerts --}}
        <article class="card panel">
            <h2 class="panel-title">System Alerts</h2>
            <div class="activity-panel">
                @forelse($alerts as $a)
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-border p-3 text-sm last:border-b-0">
                        <span class="badge {{ $a->level === 'critical' || $a->level === 'error' ? 'badge-danger' : ($a->level === 'warning' ? 'badge-warning' : 'badge-info') }}">{{ ucfirst($a->level) }}</span>
                        <span class="min-w-0 flex-1 font-medium">{{ $a->title }}
                            @if($a->message)<br><small class="text-ink-muted">{{ Str::limit($a->message, 80) }}</small>@endif
                        </span>
                        @if(! $a->is_resolved)
                            <form method="POST" action="{{ route('super.settings.alerts.resolve', $a) }}" class="inline-form">
                                @csrf
                                <button class="btn-link">Resolve</button>
                            </form>
                        @else
                            <span class="badge badge-success">Resolved</span>
                        @endif
                    </div>
                @empty
                    <p class="empty-note">No system alerts. Everything looks healthy.</p>
                @endforelse
            </div>
        </article>

        {{-- Recent system events --}}
        <article class="card panel">
            <h2 class="panel-title">Recent System Actions</h2>
            <div class="activity-panel">
                @forelse($events as $e)
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-border p-3 text-sm last:border-b-0">
                        <span class="badge badge-info">{{ ucfirst(str_replace('_', ' ', $e->type)) }}</span>
                        <span class="min-w-0 flex-1 font-medium">{{ $e->description }}
                            @if($e->meta)<br><small class="text-ink-muted">{{ $e->meta }}</small>@endif
                        </span>
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
