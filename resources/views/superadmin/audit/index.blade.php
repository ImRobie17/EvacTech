@extends('layouts.superadmin')

@section('title', 'Audit Logs')
@section('page-title', 'Audit Logs')
@section('page-subtitle', 'Full record of user actions across the system.')

@section('content')
<form method="GET" class="filter-bar" role="search">
    <input type="search" name="q" value="{{ request('q') }}" placeholder="Search description…" aria-label="Search description">
    <select name="user" aria-label="Filter user">
        <option value="">All users</option>
        @foreach($users as $u)<option value="{{ $u->id }}" @selected(request('user') == $u->id)>{{ $u->name }}</option>@endforeach
    </select>
    <select name="action" aria-label="Filter action">
        <option value="">All actions</option>
        @foreach($actions as $a)<option value="{{ $a }}" @selected(request('action') === $a)>{{ ucfirst($a) }}</option>@endforeach
    </select>
    <input type="date" name="date_from" value="{{ request('date_from') }}" aria-label="From date">
    <input type="date" name="date_to" value="{{ request('date_to') }}" aria-label="To date">
    <button type="submit" class="btn-secondary">Filter</button>
</form>

<div class="card panel table-panel">
    <table class="data-table">
        <thead>
            <tr><th scope="col">Date &amp; Time</th><th scope="col">User</th><th scope="col">Action</th><th scope="col">Description</th><th scope="col">IP Address</th></tr>
        </thead>
        <tbody>
            @forelse($logs as $log)
                <tr>
                    <td data-numeric>{{ $log->created_at?->format('M d, Y · h:i A') }}</td>
                    <td>{{ $log->user?->name ?? 'System' }}</td>
                    <td><span class="badge badge-info">{{ ucfirst($log->action) }}</span></td>
                    <td>{{ $log->description ?? '—' }}</td>
                    <td data-numeric class="text-muted">{{ $log->ip_address ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="empty-note">No audit logs match your filters.</td></tr>
            @endforelse
        </tbody>
    </table>
    {{ $logs->links() }}
</div>
@endsection
