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

{{-- ======== PHASE 7 ITEM 5 -- pending password reset requests ========
     EVERY pending request, City Admin and barangay alike, because Super Admin
     can already edit both kinds of account from the table below. The queue
     mirrors authorizeTarget() exactly: you see the requests for the accounts
     you are allowed to act on, no more and no less.

     Always rendered, empty or not. It was previously hidden when empty, which
     made "nobody has asked" and "this system has no such feature" look
     identical from the screen. The count in the heading gives back the
     at-a-glance signal without the ambiguity. --}}
<div class="card panel table-panel">
    <h2 class="panel-title">Password Reset Requests ({{ $resetRequests->count() }})</h2>
    <p class="text-sm text-ink-muted">
        Every pending request, from City Admin and barangay accounts alike.
        City Admin also sees the barangay ones, so check before you call.
        Call the person to confirm the request is really theirs before you reset anything.
        Setting a new password closes the request and clears any sign-in lock.
    </p>
    <table class="data-table mt-3" data-stack>
        <thead>
            <tr>
                <th scope="col">Name</th>
                <th scope="col">Role</th>
                <th scope="col">Email</th>
                <th scope="col">Contact Given</th>
                <th scope="col">Requested</th>
                <th scope="col">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse($resetRequests as $req)
                @php
                    $rq = $req->user;
                    $reqUser = [
                        'id' => $rq->id,
                        'name' => $rq->name,
                        'email' => $rq->email,
                        'contact_number' => $rq->contact_number,
                        'role' => $rq->role?->name,
                        'barangay_id' => $rq->barangay_id,
                        'status' => $rq->status,
                        'update_url' => route('super.users.update', $rq),
                    ];
                    $reqUserJson = json_encode($reqUser);
                @endphp
                <tr>
                    <td data-label="Name" data-fit>{{ $rq->name }}</td>
                    {{-- Which queue this would otherwise have sat in. Without it
                         a Super Admin cannot tell whether City Admin is also
                         looking at this row. --}}
                    <td data-label="Role"><span class="badge">{{ $rq->role?->display_name ?? '-' }}</span></td>
                    <td data-label="Email" class="break-all" data-fit>{{ $rq->email }}</td>
                    <td data-label="Contact Given" class="whitespace-nowrap">{{ $req->contact_number ?? $rq->contact_number ?? '-' }}</td>
                    <td data-label="Requested" class="whitespace-nowrap">{{ $req->updated_at?->diffForHumans() }}</td>
                    <td class="actions-cell" data-label="Actions">
                        <button type="button" class="btn-link" data-edit-user="{{ $reqUserJson }}">Reset password</button>
                        <form method="POST" action="{{ route('super.users.reset-requests.dismiss', $req) }}" class="inline-form"
                              data-confirm="Dismiss the request from {{ $rq->name }} without changing their password?">
                            @csrf
                            <button class="btn-link btn-link-danger">Dismiss</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="empty-note">
                        No pending password reset requests.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

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
                    <td data-label="Status">
                        <span class="badge {{ $u->status === 'active' ? 'badge-success' : 'badge-warning' }}">{{ ucfirst($u->status) }}</span>
                        {{-- PHASE 7 ITEM 4. Locked and inactive are different
                             states; the badge carries the word, not just a
                             colour. --}}
                        @if($u->isLocked())
                            <span class="badge badge-danger">Locked</span>
                        @endif
                    </td>
                    <td class="actions-cell" data-label="Actions">
                        <button type="button" class="btn-link" data-edit-user="{{ $editUserJson }}">Edit</button>
                        <form method="POST" action="{{ route('super.users.toggle', $u) }}" class="inline-form" data-confirm="Set {{ $u->name }} to {{ $u->status === 'active' ? 'inactive' : 'active' }}?">
                            @csrf
                            <button class="btn-link {{ $u->status === 'active' ? 'btn-link-danger' : '' }}">{{ $u->status === 'active' ? 'Deactivate' : 'Activate' }}</button>
                        </form>
                        @if($u->isLocked())
                            <form method="POST" action="{{ route('super.users.unlock', $u) }}" class="inline-form"
                                  data-confirm="Let {{ $u->name }} sign in again now?">
                                @csrf
                                <button class="btn-link">Unlock</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="empty-note">No accounts found.</td></tr>
            @endforelse
        </tbody>
    </table>
    {{ $users->links() }}
</div>

{{-- ======== PHASE 7 ITEM 6 -- System Administrators (read only) ========
     A separate table, not a role option in the one above. Two reasons it is
     read only and both belong in the paper: UserManagementController's
     authorizeTarget() already refuses to act on a Super Admin, so an Edit
     button here would mean deleting a guard rather than adding a feature; and
     an application that cannot mint its own top-level accounts is a smaller
     target than one that can. These accounts are provisioned at deployment.

     Status is not colour-only: the badge carries the word as well. --}}
<div class="card panel table-panel mt-6">
    <h2 class="panel-title">System Administrators</h2>
    <p class="text-sm text-ink-muted">
        Read only. Super Admin accounts are provisioned during deployment and cannot be
        created, edited or deactivated from this screen.
    </p>
    <table class="data-table mt-3" data-stack>
        <thead>
            <tr>
                <th scope="col">Name</th>
                <th scope="col">Email</th>
                <th scope="col">Contact</th>
                <th scope="col">Last Login</th>
                <th scope="col">Status</th>
            </tr>
        </thead>
        <tbody>
            @forelse($superAdmins as $sa)
                <tr>
                    <td data-label="Name" data-fit>{{ $sa->name }}</td>
                    <td data-label="Email" class="break-all" data-fit>{{ $sa->email }}</td>
                    <td data-label="Contact" class="whitespace-nowrap">{{ $sa->contact_number ?? '-' }}</td>
                    <td data-label="Last Login" data-numeric class="whitespace-nowrap">{{ $sa->last_login_at?->diffForHumans() ?? 'Never' }}</td>
                    <td data-label="Status">
                        <span class="badge {{ $sa->status === 'active' ? 'badge-success' : 'badge-warning' }}">{{ ucfirst($sa->status) }}</span>
                        {{-- PHASE 7 ITEM 6, deferred half. The one action on this
                             otherwise read-only table, and only on your OWN row:
                             the route takes no user id, so it cannot be pointed
                             at anyone else. Before this there was no in-app
                             recovery for a Super Admin password at all. --}}
                        @if($sa->id === auth()->id())
                            <button type="button" class="btn-link" data-open-modal="accountPasswordModal">Change my password</button>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="empty-note">No Super Admin accounts found. Run the SuperAdminSeeder.</td></tr>
            @endforelse
        </tbody>
    </table>
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

{{-- ======== PHASE 7 ITEM 6, deferred half -- change my own password ========
     Opened by the generic data-open-modal handler in staff.js, which the Super
     Admin layout already loads through app.js. No new JavaScript. --}}
@push('modals')
<div class="modal-backdrop" id="accountPasswordModal" hidden>
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="apTitle">
        <div class="modal-head">
            <h2 id="apTitle">Change My Password</h2>
            <button type="button" class="icon-btn" data-close-modal aria-label="Close">&times;</button>
        </div>
        <p>This changes the password for the account you are signed in with. No other account can be changed here.</p>
        <form method="POST" action="{{ route('super.account.password') }}">
            @csrf
            <div class="field">
                <label for="ap-current">Current password</label>
                <input type="password" id="ap-current" name="current_password" required autocomplete="current-password">
            </div>
            <div class="field">
                <label for="ap-new">New password <small>(min 8 characters)</small></label>
                <input type="password" id="ap-new" name="password" required minlength="8" autocomplete="new-password">
            </div>
            <div class="field">
                <label for="ap-confirm">Confirm new password</label>
                <input type="password" id="ap-confirm" name="password_confirmation" required minlength="8" autocomplete="new-password">
            </div>
            <div class="modal-actions flex flex-col gap-2 sm:flex-row sm:justify-end">
                <button type="button" class="btn-secondary" data-close-modal>Cancel</button>
                <button type="submit" class="btn-primary">Change password</button>
            </div>
        </form>
    </div>
</div>
@endpush

{{-- The ~60 lines of inline <script> that used to sit here are now
     resources/js/superadmin.js, a Vite entry loaded by layouts/superadmin.
     An inline script cannot be cached, cannot be precached by a service worker
     for roadmap item 4, and cannot be syntax-checked in the build. --}}
