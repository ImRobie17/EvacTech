@extends('layouts.superadmin')

@section('title', 'Audit Logs')
@section('page-title', 'Audit Logs')
@section('page-subtitle', 'Full record of user actions across the system.')

@section('content')
<form method="GET" class="filter-bar" role="search">
    <input type="search" name="q" value="{{ request('q') }}" placeholder="Search description&hellip;" aria-label="Search description">
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
    <table class="data-table" data-stack>
        <thead>
            <tr><th scope="col">Date &amp; Time</th><th scope="col">User</th><th scope="col">Action</th><th scope="col">Description</th><th scope="col">IP Address</th></tr>
        </thead>
        <tbody>
            @forelse($logs as $log)
                <tr>
                    {{-- This cell was format('M d, Y (raw middle dot) h:i A').
                         The separator was a non-ASCII glyph, so it had to go --
                         but it must NOT become &middot; inside the format string.
                         In a PHP date format, m, i, d, o and t are all format
                         characters, so an entity written there is expanded
                         character by character. That is exactly the bug that
                         corrupted every row of the barangay Relief Distribution
                         Log into "Mar 15, 2026 &03421515202631; 05:42 PM".

                         The separator now sits OUTSIDE format(), between two
                         calls, where it is plain HTML. The ASCII-entity
                         convention is right; it simply cannot be applied inside
                         a format string. --}}
                    <td data-label="Date" data-numeric>{{ $log->created_at?->format('M d, Y') }} &middot; {{ $log->created_at?->format('h:i A') }}</td>
                    <td data-label="User">{{ $log->user?->name ?? 'System' }}</td>
                    <td data-label="Action"><span class="badge badge-info">{{ ucfirst($log->action) }}</span></td>
                    <td data-label="Description">{{ $log->description ?? '-' }}</td>
                    <td data-label="IP" data-numeric class="text-muted">{{ $log->ip_address ?? '-' }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="empty-note">No audit logs match your filters.</td></tr>
            @endforelse
        </tbody>
    </table>
    {{ $logs->links() }}
</div>
@endsection
