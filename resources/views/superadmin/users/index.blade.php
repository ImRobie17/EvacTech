@extends('layouts.superadmin')

@section('title', 'User Management')
@section('page-title', 'User Management')
@section('page-subtitle', 'Manage City Admin and Barangay Personnel accounts.')
@section('page-actions')
    <button type="button" class="btn-primary" data-open-modal="userModal">+ Add User</button>
@endsection

@section('content')
<form method="GET" class="filter-bar" role="search">
    <input type="search" name="q" value="{{ request('q') }}" placeholder="Search name or email&hellip;" aria-label="Search user">
    <select name="role" aria-label="Filter role">
        <option value="">All roles</option>
        <option value="city_admin" @selected(request('role') === 'city_admin')>City Admin</option>
        <option value="barangay_personnel" @selected(request('role') === 'barangay_personnel')>Barangay Personnel</option>
    </select>
    <select name="status" aria-label="Filter status">
        <option value="">All statuses</option>
        <option value="active" @selected(request('status') === 'active')>Active</option>
        <option value="inactive" @selected(request('status') === 'inactive')>Inactive</option>
    </select>
    <button type="submit" class="btn-secondary">Apply</button>
</form>

<div class="card panel table-panel">
    <table class="data-table" data-stack>
        <thead>
            <tr><th scope="col">Name</th><th scope="col">Email</th><th scope="col">Role</th><th scope="col">Barangay</th><th scope="col">Last Login</th><th scope="col">Status</th><th scope="col">Actions</th></tr>
        </thead>
        <tbody>
            @forelse($users as $u)
                @php
                    $editUser = [
                        'id' => $u->id,
                        'name' => $u->name,
                        'email' => $u->email,
                        'contact_number' => $u->contact_number,
                        'role' => $u->role?->name,
                        'barangay_id' => $u->barangay_id,
                        'status' => $u->status,
                        'update_url' => route('super.users.update', $u),
                    ];
                    $editUserJson = json_encode($editUser);
                @endphp
                <tr>
                    <td data-label="Name" data-fit>{{ $u->name }}</td>
                    {{-- break-all, not break-words: an email address has no
                         spaces to break at, so it would otherwise force the
                         stacked card wider than a 380px screen. --}}
                    <td data-label="Email" class="break-all" data-fit>{{ $u->email }}</td>
                    <td data-label="Role"><span class="badge badge-info">{{ $u->role?->display_name }}</span></td>
                    <td data-label="Barangay" data-fit>{{ $u->barangay?->name ?? '-' }}</td>
                    <td data-label="Last Login" data-numeric class="whitespace-nowrap">{{ $u->last_login_at?->diffForHumans() ?? 'Never' }}</td>
                    <td data-label="Status"><span class="badge {{ $u->status === 'active' ? 'badge-success' : 'badge-warning' }}">{{ ucfirst($u->status) }}</span></td>
                    <td class="actions-cell" data-label="Actions">
                        <button type="button" class="btn-link" data-edit-user="{{ $editUserJson }}">Edit</button>
                        <form method="POST" action="{{ route('super.users.toggle', $u) }}" class="inline-form" data-confirm="Set {{ $u->name }} to {{ $u->status === 'active' ? 'inactive' : 'active' }}?">
                            @csrf
                            <button class="btn-link {{ $u->status === 'active' ? 'btn-link-danger' : '' }}">{{ $u->status === 'active' ? 'Deactivate' : 'Activate' }}</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="empty-note">No accounts found.</td></tr>
            @endforelse
        </tbody>
    </table>
    {{ $users->links() }}
</div>
@endsection

@push('modals')
{{-- data-user-form="super" pairs with data-user-form="city" on the City Admin
     modal. Both pages use the same element ids, and app.js loads cityadmin.js
     everywhere, so City Admin's initUserAdmin() used to bind here too and --
     running after this page's old inline script -- relabelled this dialog "Add
     Barangay Personnel". Behaviour now lives in resources/js/superadmin.js,
     loaded only by layouts/superadmin, and each module checks this attribute
     before touching anything. --}}
<div class="modal-backdrop" id="userModal" data-user-form="super" hidden>
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="userModalTitle">
        <div class="modal-head">
            <h2 id="userModalTitle">Add User</h2>
            <button type="button" class="icon-btn" data-close-modal aria-label="Close">&times;</button>
        </div>
        <form method="POST" action="{{ route('super.users.store') }}" id="userForm">
            @csrf
            <input type="hidden" name="_method" id="userMethod" value="POST">
            {{-- PHASE 6 ITEM 8. These three sat inside .member-grid, which is a
                 MEMBER ROW layout: two columns from 768px and FIVE from 1280px.
                 On a wide screen that squeezed a full name, an email address and
                 a phone number into narrow side-by-side boxes where none of them
                 could show their own contents. They are plain stacked fields
                 now, one per row, which is what an account form wants. --}}
            <div class="field"><label for="u-name">Full name</label><input type="text" id="u-name" name="name" required maxlength="255"></div>
            <div class="field"><label for="u-email">Email</label><input type="email" id="u-email" name="email" required></div>
            <div class="field"><label for="u-contact">Contact number <small>(optional)</small></label><input type="text" id="u-contact" name="contact_number" maxlength="20"></div>
            <div class="field">
                <label for="u-role">Role</label>
                <select id="u-role" name="role" required>
                    <option value="city_admin">City Admin</option>
                    <option value="barangay_personnel">Barangay Personnel</option>
                </select>
            </div>
            {{-- Shown only for barangay personnel. superadmin.js toggles the
                 `hidden` attribute rather than style.display, and disables the
                 select while hidden so a leftover barangay id from a previous
                 edit is not submitted for a City Admin account. --}}
            <div class="field" id="u-barangay-field">
                <label for="u-barangay">Assigned barangay <small>(barangay personnel only)</small></label>
                <select id="u-barangay" name="barangay_id">
                    <option value="">Select barangay&hellip;</option>
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

{{-- The ~60 lines of inline <script> that used to sit here are now
     resources/js/superadmin.js, a Vite entry loaded by layouts/superadmin.
     An inline script cannot be cached, cannot be precached by a service worker
     for roadmap item 4, and cannot be syntax-checked in the build. --}}
