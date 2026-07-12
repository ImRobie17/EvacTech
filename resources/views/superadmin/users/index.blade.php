@extends('layouts.superadmin')

@section('title', 'User Management')
@section('page-title', 'User Management')
@section('page-subtitle', 'Manage City Admin and Barangay Personnel accounts.')
@section('page-actions')
    <button type="button" class="btn-primary" data-open-modal="userModal">+ Add User</button>
@endsection

@section('content')
<form method="GET" class="filter-bar" role="search">
    <input type="search" name="q" value="{{ request('q') }}" placeholder="Search name or email…" aria-label="Search user">
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
    <button type="submit" class="btn-secondary">Filter</button>
</form>

<div class="card panel table-panel">
    <table class="data-table">
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
                @endphp
                <tr>
                    <td>{{ $u->name }}</td>
                    <td>{{ $u->email }}</td>
                    <td><span class="badge badge-info">{{ $u->role?->display_name }}</span></td>
                    <td>{{ $u->barangay?->name ?? '—' }}</td>
                    <td data-numeric>{{ $u->last_login_at?->diffForHumans() ?? 'Never' }}</td>
                    <td><span class="badge {{ $u->status === 'active' ? 'badge-success' : 'badge-warning' }}">{{ ucfirst($u->status) }}</span></td>
                    <td class="actions-cell">
                        <button type="button" class="btn-link" data-edit-user="{{ json_encode($editUser) }}">Edit</button>
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
<div class="modal-backdrop" id="userModal" hidden>
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="userModalTitle">
        <div class="modal-head">
            <h2 id="userModalTitle">Add User</h2>
            <button type="button" class="icon-btn" data-close-modal aria-label="Close">&times;</button>
        </div>
        <form method="POST" action="{{ route('super.users.store') }}" id="userForm">
            @csrf
            <input type="hidden" name="_method" id="userMethod" value="POST">
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
            <div class="field" id="u-barangay-field">
                <label for="u-barangay">Assigned barangay <small>(barangay personnel only)</small></label>
                <select id="u-barangay" name="barangay_id">
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

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('userForm');
    const title = document.getElementById('userModalTitle');
    const submit = document.getElementById('userSubmit');
    const methodInput = document.getElementById('userMethod');
    const statusField = document.getElementById('u-status-field');
    const roleSelect = document.getElementById('u-role');
    const barangayField = document.getElementById('u-barangay-field');
    const pw = document.getElementById('u-password');
    const pwHint = document.getElementById('u-pw-hint');
    const storeUrl = form.getAttribute('action');

    function openModal(id){ document.getElementById(id).hidden = false; }

    // Show/hide barangay field based on role
    function syncBarangay() {
        barangayField.style.display = roleSelect.value === 'barangay_personnel' ? '' : 'none';
    }
    roleSelect.addEventListener('change', syncBarangay);

    document.querySelectorAll('[data-open-modal="userModal"]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            form.reset();
            form.action = storeUrl;
            methodInput.value = 'POST';
            title.textContent = 'Add User';
            submit.textContent = 'Create Account';
            statusField.hidden = true;
            pwHint.textContent = '(min 8 characters)';
            pw.required = true;
            syncBarangay();
        });
    });

    document.querySelectorAll('[data-edit-user]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const d = JSON.parse(btn.dataset.editUser);
            form.reset();
            form.action = d.update_url;
            methodInput.value = 'PUT';
            title.textContent = 'Edit User';
            submit.textContent = 'Save Changes';
            statusField.hidden = false;
            pwHint.textContent = '(leave blank to keep current)';
            pw.required = false;

            document.getElementById('u-name').value = d.name;
            document.getElementById('u-email').value = d.email;
            document.getElementById('u-contact').value = d.contact_number || '';
            roleSelect.value = d.role || 'barangay_personnel';
            document.getElementById('u-barangay').value = d.barangay_id || '';
            document.getElementById('u-status').value = d.status;
            syncBarangay();
            openModal('userModal');
        });
    });

    syncBarangay();
});
</script>
@endpush
