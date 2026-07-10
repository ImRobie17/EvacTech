@extends('layouts.cityadmin')

@section('title', 'User Management')
@section('page-title', 'User Management')
@section('page-subtitle', 'Manage barangay personnel accounts and shelter assignments.')
@section('page-actions')
    <button type="button" class="btn-primary" data-open-modal="userModal" data-mode="create">+ Add Personnel</button>
@endsection

@section('content')
<form method="GET" class="filter-bar" role="search">
    <input type="search" name="q" value="{{ request('q') }}" placeholder="Search name or email…" aria-label="Search user">
    <select name="barangay" aria-label="Filter barangay">
        <option value="">All barangays</option>
        @foreach($barangays as $b)<option value="{{ $b->id }}" @selected(request('barangay') == $b->id)>{{ $b->name }}</option>@endforeach
    </select>
    <select name="status" aria-label="Filter status">
        <option value="">All statuses</option>
        <option value="active" @selected(request('status') === 'active')>Active</option>
        <option value="inactive" @selected(request('status') === 'inactive')>Inactive</option>
    </select>
    <button type="submit" class="btn-secondary">Filter</button>
</form>

<div class="card panel table-panel">
    <table class="data-table">
        <thead>
            <tr><th scope="col">Name</th><th scope="col">Email</th><th scope="col">Barangay</th><th scope="col">Last Login</th><th scope="col">Status</th><th scope="col">Actions</th></tr>
        </thead>
        <tbody>
            @forelse($users as $u)
                @php
                    $editUser = [
                        'id' => $u->id,
                        'name' => $u->name,
                        'email' => $u->email,
                        'contact_number' => $u->contact_number,
                        'barangay_id' => $u->barangay_id,
                        'status' => $u->status,
                        'update_url' => route('city.users.update', $u),
                    ];
                @endphp
                <tr>
                    <td>{{ $u->name }}</td>
                    <td>{{ $u->email }}</td>
                    <td>{{ $u->barangay?->name ?? '—' }}</td>
                    <td data-numeric>{{ $u->last_login_at?->diffForHumans() ?? 'Never' }}</td>
                    <td><span class="badge {{ $u->status === 'active' ? 'badge-success' : 'badge-warning' }}">{{ ucfirst($u->status) }}</span></td>
                    <td class="actions-cell">
                        <button type="button" class="btn-link" data-edit-user="{{ json_encode($editUser) }}">Edit</button>
                        <form method="POST" action="{{ route('city.users.toggle', $u) }}" class="inline-form"
                              data-confirm="Set {{ $u->name }} to {{ $u->status === 'active' ? 'inactive' : 'active' }}?">
                            @csrf
                            <button class="btn-link {{ $u->status === 'active' ? 'btn-link-danger' : '' }}">{{ $u->status === 'active' ? 'Deactivate' : 'Activate' }}</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="empty-note">No barangay personnel accounts yet.</td></tr>
            @endforelse
        </tbody>
    </table>
    {{ $users->links() }}
</div>
@endsection

@push('modals')
<div class="modal-backdrop" id="userModal" hidden>
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="userModalTitle">
        <div class="modal-head">
            <h2 id="userModalTitle">Add Barangay Personnel</h2>
            <button type="button" class="icon-btn" data-close-modal aria-label="Close">&times;</button>
        </div>
        <form method="POST" action="{{ route('city.users.store') }}" id="userForm">
            @csrf
            <input type="hidden" name="_method" id="userMethod" value="POST">
            <div class="field"><label for="u-name">Full name</label><input type="text" id="u-name" name="name" required maxlength="255"></div>
            <div class="field"><label for="u-email">Email</label><input type="email" id="u-email" name="email" required></div>
            <div class="field"><label for="u-contact">Contact number <small>(optional)</small></label><input type="text" id="u-contact" name="contact_number" maxlength="20"></div>
            <div class="field">
                <label for="u-barangay">Assigned barangay</label>
                <select id="u-barangay" name="barangay_id" required>
                    <option value="">Select barangay…</option>
                    @foreach($barangays as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach
                </select>
            </div>
            <div class="field" id="u-status-field" hidden>
                <label for="u-status">Status</label>
                <select id="u-status" name="status"><option value="active">Active</option><option value="inactive">Inactive</option></select>
            </div>
            <div class="field">
                <label for="u-password">Password <small id="u-pw-hint">(min 8 characters)</small></label>
                <input type="password" id="u-password" name="password" minlength="8">
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-secondary" data-close-modal>Cancel</button>
                <button type="submit" class="btn-primary" id="userSubmit">Create Account</button>
            </div>
        </form>
    </div>
</div>
@endpush
