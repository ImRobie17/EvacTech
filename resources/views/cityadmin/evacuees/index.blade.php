@extends('layouts.cityadmin')

@section('title', 'Evacuee Profiling')
@section('page-title', 'Evacuee Profiling')
@section('page-subtitle', 'All registered households across every shelter.')
@section('page-actions')
    <button type="button" class="btn-primary" data-open-modal="cityEvacueeModal">+ Register Household</button>
@endsection

@section('content')
<form method="GET" class="filter-bar" role="search">
    <input type="search" name="q" value="{{ request('q') }}" placeholder="Search household head…" aria-label="Search head">
    <select name="barangay" aria-label="Filter barangay">
        <option value="">All barangays</option>
        @foreach($barangays as $b)<option value="{{ $b->id }}" @selected(request('barangay') == $b->id)>{{ $b->name }}</option>@endforeach
    </select>
    <select name="shelter" aria-label="Filter shelter">
        <option value="">All shelters</option>
        @foreach($shelters as $s)<option value="{{ $s->id }}" @selected(request('shelter') == $s->id)>{{ $s->name }}</option>@endforeach
    </select>
    <select name="status" aria-label="Filter status">
        <option value="">All statuses</option>
        @foreach(['registered' => 'Registered', 'checked_in' => 'Checked in', 'checked_out' => 'Checked out', 'transferred' => 'Transferred'] as $val => $label)
            <option value="{{ $val }}" @selected(request('status') === $val)>{{ $label }}</option>
        @endforeach
    </select>
    <button type="submit" class="btn-secondary">Filter</button>
</form>

<div class="card panel table-panel">
    <table class="data-table">
        <thead>
            <tr><th scope="col">Household ID</th><th scope="col">Head</th><th scope="col">Size</th><th scope="col">Barangay</th><th scope="col">Shelter</th><th scope="col">Tags</th><th scope="col">Status</th></tr>
        </thead>
        <tbody>
            @forelse($households as $h)
                <tr>
                    <td data-numeric>{{ $h->household_code }}</td>
                    <td>{{ $h->headMember?->full_name ?? '—' }}</td>
                    <td data-numeric>{{ $h->number_of_members }}</td>
                    <td>{{ $h->originBarangay?->name }}</td>
                    <td>{{ $h->evacuationCenter?->name ?? '—' }}</td>
                    <td>
                        @php $tags = $h->members->flatMap->vulnerableClassifications->unique('id'); @endphp
                        @forelse($tags as $tag)<span class="badge badge-info">{{ $tag->name }}</span>@empty<span class="text-muted">None</span>@endforelse
                    </td>
                    <td><span class="badge {{ $h->status === 'checked_in' ? 'badge-success' : 'badge-warning' }}">{{ ucfirst(str_replace('_', ' ', $h->status)) }}</span></td>
                </tr>
            @empty
                <tr><td colspan="7" class="empty-note">No households found.</td></tr>
            @endforelse
        </tbody>
    </table>
    {{ $households->links() }}
</div>
@endsection

@push('modals')
<div class="modal-backdrop" id="cityEvacueeModal" hidden>
    <div class="modal modal-wide" role="dialog" aria-modal="true" aria-labelledby="cityEvacueeTitle">
        <div class="modal-head">
            <h2 id="cityEvacueeTitle">Register Household</h2>
            <button type="button" class="icon-btn" data-close-modal aria-label="Close">&times;</button>
        </div>
        <form method="POST" action="{{ route('city.evacuees.store') }}" id="cityEvacueeForm">
            @csrf
            <div class="member-grid">
                <div class="field">
                    <label for="ce-barangay">Origin barangay</label>
                    <select id="ce-barangay" name="origin_barangay_id" required>
                        <option value="">Select…</option>
                        @foreach($barangays as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="ce-shelter">Evacuation shelter</label>
                    <select id="ce-shelter" name="evacuation_center_id" required>
                        <option value="">Select…</option>
                        @foreach($shelters as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach
                    </select>
                </div>
            </div>
            <div class="field"><label for="ce-address">Family address</label><input type="text" id="ce-address" name="address" required maxlength="255"></div>

            <fieldset class="member-fieldset" id="ceHeadFieldset"><legend>Household Head</legend><div id="ceHeadRow"></div></fieldset>
            <fieldset class="member-fieldset"><legend>Family Members</legend><div id="ceMemberRows"></div>
                <button type="button" class="btn-secondary" id="ceAddMemberBtn">+ Add family member</button>
            </fieldset>

            <div class="modal-actions">
                <button type="button" class="btn-secondary" data-close-modal>Cancel</button>
                <button type="submit" class="btn-secondary" name="checkin" value="0">Save</button>
                <button type="submit" class="btn-primary" name="checkin" value="1">Save &amp; Check-in</button>
            </div>
        </form>
    </div>
</div>

<template id="ceMemberRowTemplate">
    <div class="member-row" data-row>
        <input type="hidden" data-field="id" name="">
        <div class="member-grid">
            <div class="field"><label>Last name</label><input type="text" data-field="last_name" required maxlength="100"></div>
            <div class="field"><label>First name</label><input type="text" data-field="first_name" required maxlength="100"></div>
            <div class="field"><label>Middle name <small>(optional)</small></label><input type="text" data-field="middle_name" maxlength="100"></div>
            <div class="field"><label>Date of birth</label><input type="date" data-field="birthdate" required max="{{ now()->toDateString() }}"></div>
            <div class="field"><label>Sex</label><select data-field="sex" required><option value="">Select…</option><option value="male">Male</option><option value="female">Female</option></select></div>
        </div>
        <div class="member-tags-row">
            <span class="age-tag badge badge-info" data-age-tag hidden></span>
            <label class="tags-label">Special needs / classifications:</label>
            <select data-field="tags" multiple size="1" aria-label="Vulnerability classifications">
                @foreach($classifications as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
            </select>
            <button type="button" class="btn-link btn-link-danger" data-remove-row>Remove person</button>
        </div>
    </div>
</template>
@endpush

@push('scripts')
<script>
    window.CityEvacueeConfig = { autoOpen: false };
</script>
@endpush
