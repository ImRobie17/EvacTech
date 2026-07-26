@extends('layouts.staff')

@section('title', 'Evacuee Profiling')
@section('page-title', 'Evacuee Profiling')
@section('page-subtitle', 'Register households and manage evacuee records.')

@section('page-actions')
    <button type="button" class="btn-primary" data-open-modal="evacueeModal" data-mode="create">+ Register Household</button>
@endsection

@section('content')
<form method="GET" class="filter-bar" role="search">
    <input type="search" name="q" value="{{ request('q') }}" placeholder="Search household head&hellip;" aria-label="Search household head">
    <select name="status" aria-label="Filter by status">
        <option value="">All statuses</option>
        @foreach(['registered' => 'Registered', 'checked_in' => 'Checked in', 'checked_out' => 'Checked out', 'transferred' => 'Transferred'] as $val => $label)
            <option value="{{ $val }}" @selected(request('status') === $val)>{{ $label }}</option>
        @endforeach
    </select>
    {{-- One shelter can hold families from several barangays, so origin
         barangay is now a filter too. --}}
    <select name="barangay" aria-label="Filter by origin barangay">
        <option value="">All origin barangays</option>
        @foreach($barangays as $b)
            <option value="{{ $b->id }}" @selected(request('barangay') == $b->id)>{{ $b->name }}</option>
        @endforeach
    </select>
    <select name="vulnerable" aria-label="Filter by vulnerability tag">
        <option value="">All vulnerability tags</option>
        @foreach($classifications as $c)
            <option value="{{ $c->id }}" @selected(request('vulnerable') == $c->id)>{{ $c->name }}</option>
        @endforeach
    </select>
    <button type="submit" class="btn-secondary">Filter</button>
</form>

<div class="card panel table-panel">
    <table class="data-table">
        <thead>
            <tr>
                <th scope="col">Household ID</th>
                <th scope="col">Household Head</th>
                <th scope="col">Family Size</th>
                <th scope="col">Address</th>
                <th scope="col">Shelter</th>
                <th scope="col">Vulnerable Tags</th>
                <th scope="col">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse($households as $h)
                <tr>
                    <td data-numeric>{{ $h->household_code }}</td>
                    <td>{{ $h->headMember?->full_name ?? '-' }}</td>
                    <td data-numeric>{{ $h->number_of_members }}</td>
                    <td>{{ $h->origin_address }}</td>
                    <td>{{ $h->evacuationCenter?->name ?? '-' }}</td>
                    <td>
                        @php
                            $tags = $h->members->flatMap->vulnerableClassifications->unique('id');
                        @endphp
                        @forelse($tags as $tag)
                            <span class="badge badge-info">{{ $tag->name }}</span>
                        @empty
                            <span class="text-muted">None</span>
                        @endforelse
                    </td>
                    <td class="actions-cell">
                        <button type="button" class="btn-link" data-open-modal="evacueeModal" data-mode="edit" data-household="{{ $h->id }}">Edit Family Group</button>
                        <form method="POST" action="{{ route('barangay.evacuees.destroy', $h) }}" class="inline-form" data-confirm="Remove household {{ $h->household_code }}? This cannot be undone.">
                            @csrf @method('DELETE')
                            <button type="submit" class="btn-link btn-link-danger">Remove</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="empty-note">No households registered yet. Select "Register Household" to add the first family.</td></tr>
            @endforelse
        </tbody>
    </table>
    {{ $households->links() }}
</div>
@endsection

@push('modals')
{{-- ======== Add / Edit Evacuee modal ======== --}}
<div class="modal-backdrop" id="evacueeModal" hidden>
    <div class="modal modal-wide" role="dialog" aria-modal="true" aria-labelledby="evacueeModalTitle">
        <div class="modal-head">
            <h2 id="evacueeModalTitle">Add New Evacuee Profile</h2>
            <button type="button" class="icon-btn" data-close-modal aria-label="Close">&times;</button>
        </div>

        <form method="POST" id="evacueeForm" action="{{ route('barangay.evacuees.store') }}">
            @csrf
            <input type="hidden" name="_method" value="POST" id="evacueeFormMethod">

            {{-- Origin barangay is now EXPLICIT. It used to be inferred from
                 auth()->user()->barangay_id, but staff are assigned to shelters
                 rather than barangays, and one shelter routinely holds families
                 from several barangays. Defaults to the barangay of the active shelter,
                 which covers the common case in one click. --}}
            <div class="member-grid">
                <div class="field">
                    <label for="ev-barangay">Origin barangay</label>
                    <select id="ev-barangay" name="origin_barangay_id" required>
                        <option value="">Select barangay</option>
                        @foreach($barangays as $b)
                            <option value="{{ $b->id }}" @selected(($defaultBarangayId ?? null) == $b->id)>{{ $b->name }}</option>
                        @endforeach
                    </select>
                    <small class="field-hint">Where the family came from, not where they are sheltering.</small>
                </div>
                <div class="field">
                    <label for="ev-address">Family address (house no., street, purok)</label>
                    <input type="text" id="ev-address" name="address" required maxlength="255">
                </div>
            </div>

            <fieldset class="member-fieldset" id="headFieldset">
                <legend>Household Head</legend>
                <div id="headRow"></div>
            </fieldset>

            <fieldset class="member-fieldset">
                <legend>Family Members</legend>
                <div id="memberRows"></div>
                <button type="button" class="btn-secondary" id="addMemberBtn">+ Add family member</button>
            </fieldset>

            <div class="modal-actions">
                <button type="button" class="btn-link" id="transferHeadBtn" hidden>Transfer Head&hellip;</button>
                <span class="spacer"></span>
                <button type="button" class="btn-secondary" data-close-modal>Cancel</button>
                <button type="submit" class="btn-secondary" name="checkin" value="0">Save</button>
                <button type="submit" class="btn-primary" name="checkin" value="1" id="saveCheckinBtn">Save &amp; Check-in</button>
            </div>
        </form>
    </div>
</div>

{{-- ======== Select New Family Head modal (shared markup/IDs with Shelter page's JS) ======== --}}
<div class="modal-backdrop" id="transferHeadModal" hidden>
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="transferTitle">
        <div class="modal-head">
            <h2 id="transferTitle">Select New Family Head</h2>
            <button type="button" class="icon-btn" data-close-modal aria-label="Close">&times;</button>
        </div>
        <p>Current head: <strong id="th-current"></strong></p>
        <div id="th-options" class="radio-list" role="radiogroup" aria-label="Select the new household head"></div>
        <div class="modal-actions">
            <button type="button" class="btn-secondary" data-close-modal>Cancel</button>
            <button type="button" class="btn-primary" id="th-next">Continue</button>
        </div>
    </div>
</div>

{{-- ======== Confirm Transfer modal ======== --}}
<div class="modal-backdrop" id="confirmTransferModal" hidden>
    <div class="modal modal-narrow" role="dialog" aria-modal="true" aria-labelledby="confirmTransferTitle">
        <div class="modal-head">
            <h2 id="confirmTransferTitle">Confirm Transfer</h2>
            <button type="button" class="icon-btn" data-close-modal aria-label="Close">&times;</button>
        </div>
        <p>You are about to transfer the family head role to <strong id="ct-name"></strong>. This is recorded permanently.</p>
        <form method="POST" id="confirmTransferForm">
            @csrf
            <input type="hidden" name="new_head_member_id" id="ct-member-id">
            <div class="field">
                <label for="ct-confirmation">Type <strong>transfer</strong> to confirm</label>
                <input type="text" id="ct-confirmation" name="confirmation" autocomplete="off" required>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-secondary" data-close-modal>Cancel</button>
                <button type="submit" class="btn-danger">Confirm Transfer</button>
            </div>
        </form>
    </div>
</div>

{{-- Row template used by JS for both head and members --}}
<template id="memberRowTemplate">
    <div class="member-row" data-row>
        <input type="hidden" data-field="id" name="">
        <div class="member-grid">
            <div class="field"><label>Last name</label><input type="text" data-field="last_name" required maxlength="100"></div>
            <div class="field"><label>First name</label><input type="text" data-field="first_name" required maxlength="100"></div>
            <div class="field"><label>Middle name <small>(optional)</small></label><input type="text" data-field="middle_name" maxlength="100"></div>
            <div class="field"><label>Date of birth</label><input type="date" data-field="birthdate" required max="{{ now()->toDateString() }}"></div>
            <div class="field"><label>Sex</label>
                <select data-field="sex" required>
                    <option value="">Select&hellip;</option>
                    <option value="male">Male</option>
                    <option value="female">Female</option>
                </select>
            </div>
        </div>
        <div class="member-tags-row">
            <span class="age-tag badge badge-info" data-age-tag hidden></span>
            <label class="tags-label">Special needs / classifications:</label>
            <select data-field="tags" multiple size="1" aria-label="Vulnerability classifications">
                @foreach($classifications as $c)
                    <option value="{{ $c->id }}">{{ $c->name }}</option>
                @endforeach
            </select>
            <button type="button" class="btn-link btn-link-danger" data-remove-row>Remove person</button>
        </div>
    </div>
</template>
@endpush

@push('scripts')
<script>
    window.EvacueeConfig = {
        showUrlTemplate: "{{ route('barangay.evacuees.show', ':id') }}",
        updateUrlTemplate: "{{ route('barangay.evacuees.update', ':id') }}",
        storeUrl: "{{ route('barangay.evacuees.store') }}",
        defaultBarangayId: {{ (int) ($defaultBarangayId ?? 0) }},
        autoOpen: @json(request('open') === 'register'),
    };
    // Reuses the same transfer-flow modals/JS as the Shelter page.
    window.ShelterConfig = window.ShelterConfig || {
        transferUrlTemplate: "{{ route('barangay.shelter.transfer', ':id') }}",
    };
</script>
@endpush
