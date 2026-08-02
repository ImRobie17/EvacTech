@extends('layouts.cityadmin')

@section('title', 'User Management')
@section('page-title', 'User Management')
@section('page-subtitle', 'Manage barangay personnel accounts and their shelter assignments.')
@section('page-actions')
    <button type="button" class="btn-primary" data-open-modal="userModal" data-mode="create">+ Add Personnel</button>
@endsection

@section('content')
<form method="GET" class="filter-bar" role="search">
    <input type="search" name="q" value="{{ request('q') }}" placeholder="Search name or email" aria-label="Search user">
    <select name="shelter" aria-label="Filter by assigned shelter">
        <option value="">All shelters</option>
        @foreach($shelters as $s)
            <option value="{{ $s->id }}" @selected(request('shelter') == $s->id)>{{ $s->name }}</option>
        @endforeach
    </select>
    <select name="status" aria-label="Filter status">
        <option value="">All statuses</option>
        <option value="active" @selected(request('status') === 'active')>Active</option>
        <option value="inactive" @selected(request('status') === 'inactive')>Inactive</option>
    </select>
    <label class="checkbox-row filter-check">
        <input type="checkbox" name="unassigned" value="1" @checked(request('unassigned'))>
        Unassigned only
    </label>
    <button type="submit" class="btn-secondary">Apply</button>
</form>

<div class="card panel table-panel">
    <table class="data-table" data-stack>
        <thead>
            <tr>
                <th scope="col">Name</th>
                <th scope="col">Email</th>
                <th scope="col">Assigned Shelters</th>
                <th scope="col">Last Login</th>
                <th scope="col">Status</th>
                <th scope="col">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse($users as $u)
                @php
                    $shelterIds = $u->assignedCenters->pluck('id')->values()->all();
                    $editUser = [
                        'id' => $u->id,
                        'name' => $u->name,
                        'email' => $u->email,
                        'contact_number' => $u->contact_number,
                        'shelters' => $shelterIds,
                        'status' => $u->status,
                        'update_url' => route('city.users.update', $u),
                    ];
                    $editUserJson = json_encode($editUser);
                @endphp
                <tr>
                    <td data-label="Name" data-fit>{{ $u->name }}</td>
                    {{-- Long addresses must wrap rather than force the card wide
                         at 380px. break-all is deliberate: an email has no spaces
                         to break at, so break-words alone would not help. --}}
                    <td data-label="Email" class="break-all" data-fit>{{ $u->email }}</td>
                    <td data-label="Shelters">
                        @if ($u->assignedCenters->isEmpty())
                            <span class="badge badge-danger">No shelter assigned</span>
                        @else
                            <details class="staff-list">
                                <summary>{{ $u->assignedCenters->count() }} shelter{{ $u->assignedCenters->count() === 1 ? '' : 's' }}</summary>
                                <ul>
                                    @foreach ($u->assignedCenters as $c)
                                        <li>{{ $c->name }}@if($c->barangay) <small>Brgy. {{ $c->barangay->name }}</small>@endif</li>
                                    @endforeach
                                </ul>
                            </details>
                        @endif
                    </td>
                    <td data-label="Last Login" data-numeric class="whitespace-nowrap">{{ $u->last_login_at?->diffForHumans() ?? 'Never' }}</td>
                    <td data-label="Status"><span class="badge {{ $u->status === 'active' ? 'badge-success' : 'badge-warning' }}">{{ ucfirst($u->status) }}</span></td>
                    <td class="actions-cell" data-label="Actions">
                        <button type="button" class="btn-link" data-edit-user="{{ $editUserJson }}">Edit</button>
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
{{-- data-user-form="city" is the cross-role guard added in Chat C.

     app.js loads cityadmin.js on every page, and the Super Admin users page
     uses these very same element ids (userModal, userForm, userMethod, u-name,
     u-status-field, u-pw-hint, [data-edit-user]). initUserAdmin() guarded only
     on #userModal existing, so it bound to Super Admin's buttons as well and,
     running last, relabelled that page's dialog "Add Barangay Personnel".

     Super Admin now has its own bundle (resources/js/superadmin.js). This
     attribute is the second, independent line of defence: each module refuses
     to touch a form that is not its own. --}}
<div class="modal-backdrop" id="userModal" data-user-form="city" hidden>
    <div class="modal modal-wide" role="dialog" aria-modal="true" aria-labelledby="userModalTitle">
        <div class="modal-head">
            <h2 id="userModalTitle">Add Barangay Personnel</h2>
            <button type="button" class="icon-btn" data-close-modal aria-label="Close">&times;</button>
        </div>
        <form method="POST" action="{{ route('city.users.store') }}" id="userForm">
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

            {{-- Shelter assignment REPLACES the old "Assigned barangay" dropdown.
                 Access follows the shelter roster, not the barangay.

                 PHASE 6. Radio, not checkbox. A staff member is physically inside
                 ONE shelter for one shift; moving them to another is a
                 REASSIGNMENT, so picking the new shelter must release the old one
                 in the same action rather than quietly granting both. This is
                 also what makes removing the header switcher safe -- with exactly
                 one shelter on the roster there is nothing left to switch between.

                 The input NAME stays `shelters[]`. A radio group posts a
                 single-element array, so the controller's existing
                 `array|min:1` rules and syncShelters() need no change, and the
                 pivot stays many-to-many for any future surge-staffing case. --}}
            <fieldset class="member-fieldset">
                <legend>Assigned shelter</legend>
                <p class="field-hint">
                    The one shelter this staff member operates. Choosing a different
                    shelter here reassigns them and revokes the previous one.
                </p>
                <div class="roster-toolbar">
                    <input type="search" id="u-shelter-search" class="roster-search" placeholder="Filter shelters" aria-label="Filter shelter list">
                    <span class="roster-count" id="u-shelter-count">None selected</span>
                </div>
                {{-- radiogroup, not group: one choice, and screen readers should
                     say so. --}}
                <div class="roster-list" id="u-shelter-list" role="radiogroup" aria-label="Assigned shelter">
                    @forelse($shelters as $s)
                        <label class="radio-row roster-row" data-shelter-name="{{ strtolower($s->name . ' ' . ($s->barangay?->name ?? '')) }}">
                            <input type="radio" name="shelters[]" value="{{ $s->id }}">
                            <span class="roster-name">{{ $s->name }}</span>
                            <span class="roster-meta">
                                {{ $s->barangay?->name ? 'Brgy. ' . $s->barangay->name : '' }}
                                @if ($s->status !== 'active') &middot; Inactive @endif
                            </span>
                        </label>
                    @empty
                        <p class="empty-note">No shelters exist yet. Add one in Evacuation Shelters first.</p>
                    @endforelse
                </div>
            </fieldset>

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
