@extends('layouts.cityadmin')

@section('title', 'Evacuation Shelters')
@section('page-title', 'Evacuation Shelters')
@section('page-subtitle', 'All evacuation centers across the city. A barangay may have several.')
@section('page-actions')
    <button type="button" class="btn-primary" data-open-modal="addShelterModal">+ Add Shelter</button>
@endsection

@section('content')
<form method="GET" class="filter-bar" role="search">
    <input type="search" name="q" value="{{ request('q') }}" placeholder="Search shelter name" aria-label="Search shelter">
    <select name="barangay" aria-label="Filter barangay">
        <option value="">All barangays</option>
        @foreach($barangays as $b)
            <option value="{{ $b->id }}" @selected(request('barangay') == $b->id)>{{ $b->name }}</option>
        @endforeach
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
            <tr>
                <th scope="col">Shelter</th>
                <th scope="col">Barangay</th>
                <th scope="col">Location</th>
                <th scope="col">Capacity</th>
                <th scope="col">Occupancy</th>
                <th scope="col">Assigned Staff</th>
                <th scope="col">Status</th>
                <th scope="col">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse($centers as $c)
                {{-- GOTCHA #1: arrays are built in a PHP block and emitted with
                     json_encode(). Never inline a Blade json directive containing
                     arrows or spanning multiple lines: it fails to parse. --}}
                @php
                    $pct = $c->capacity > 0 ? round($c->current_occupancy / $c->capacity * 100) : null;
                    $band = $c->capacityBand();
                    $capClass = 'cap-' . $band;
                    $staffIds = $c->assignedStaff->pluck('id')->values()->all();
                    $editShelter = [
                        'id' => $c->id,
                        'name' => $c->name,
                        'address' => $c->address,
                        'capacity' => $c->capacity,
                        'status' => $c->status,
                        'barangay_id' => $c->barangay_id,
                        'latitude' => $c->latitude,
                        'longitude' => $c->longitude,
                        'staff' => $staffIds,
                        'has_water_supply' => (bool) $c->has_water_supply,
                        'has_medical_desk' => (bool) $c->has_medical_desk,
                        'has_power' => (bool) $c->has_power,
                        'has_communal_kitchen' => (bool) $c->has_communal_kitchen,
                        'update_url' => route('city.shelters.update', $c),
                    ];
                    $editShelterJson = json_encode($editShelter);
                @endphp
                <tr>
                    <td>{{ $c->name }}</td>
                    <td>{{ $c->barangay?->name }}</td>
                    <td>{{ $c->address }}</td>
                    <td data-numeric>{{ number_format($c->capacity) }}</td>
                    <td data-numeric class="{{ $capClass }}">
                        {{ number_format($c->current_occupancy) }}{{ $pct !== null ? " ({$pct}%)" : '' }}
                        @if ($c->isOvercapacity())
                            <small class="over-note">+{{ number_format($c->overBy()) }} over</small>
                        @endif
                    </td>
                    <td>
                        @if ($c->staff_count > 0)
                            <details class="staff-list">
                                <summary>{{ $c->staff_count }} assigned</summary>
                                <ul>
                                    @foreach ($c->assignedStaff as $s)
                                        <li>{{ $s->name }}</li>
                                    @endforeach
                                </ul>
                            </details>
                        @else
                            <span class="badge badge-warning">None assigned</span>
                        @endif
                    </td>
                    <td>
                        @include('partials.status-badge', ['center' => $c])
                    </td>
                    <td class="actions-cell">
                        <a class="btn-link" href="{{ route('city.shelters.show', $c) }}">View Details</a>
                        <button type="button" class="btn-link" data-edit-shelter="{{ $editShelterJson }}">Edit</button>
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="empty-note">No shelters found. Select "Add Shelter" to create the first one.</td></tr>
            @endforelse
        </tbody>
    </table>
    {{ $centers->links() }}
</div>
@endsection

@push('modals')
<div class="modal-backdrop" id="addShelterModal" hidden>
    <div class="modal modal-wide" role="dialog" aria-modal="true" aria-labelledby="addShelterTitle">
        <div class="modal-head">
            <h2 id="addShelterTitle">Add Evacuation Shelter</h2>
            <button type="button" class="icon-btn" data-close-modal aria-label="Close">&times;</button>
        </div>
        <form method="POST" action="{{ route('city.shelters.store') }}" id="shelterForm">
            @csrf
            <input type="hidden" name="_method" id="shelterMethod" value="POST">

            <div class="field"><label for="sh-name">Shelter name</label><input type="text" id="sh-name" name="name" required maxlength="255"></div>

            <div class="field" id="sh-barangay-field">
                <label for="sh-barangay">Barangay</label>
                <select id="sh-barangay" name="barangay_id" required>
                    <option value="">Select barangay</option>
                    @foreach($barangays as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach
                </select>
                <small class="field-hint">A barangay can hold as many shelters as needed.</small>
            </div>

            <div class="field"><label for="sh-address">Address</label><input type="text" id="sh-address" name="address" required maxlength="255"></div>

            <div class="member-grid">
                <div class="field"><label for="sh-capacity">Capacity (persons)</label><input type="number" id="sh-capacity" name="capacity" min="1" required></div>
                <div class="field"><label for="sh-lat">Latitude <small>(optional)</small></label><input type="number" step="any" id="sh-lat" name="latitude"></div>
                <div class="field"><label for="sh-lng">Longitude <small>(optional)</small></label><input type="number" step="any" id="sh-lng" name="longitude"></div>
            </div>

            <div class="field" id="sh-status-field" hidden>
                <label for="sh-status">Status</label>
                <select id="sh-status" name="status">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
                <small class="field-hint">
                    An overcapacity shelter stays <strong>Active</strong> and keeps accepting and
                    tracking evacuees. Overcapacity is shown automatically from the headcount.
                </small>
            </div>

            {{-- ---- Flat staff roster. No lead, no shift: staff rotate across three
                 shifts and work overtime during bad events, so everyone assigned has
                 equal, always-on rights over the shelter. ---- --}}
            <fieldset class="member-fieldset">
                <legend>Assigned staff</legend>
                <p class="field-hint">
                    Every staff member ticked here has equal rights over this shelter.
                    Staff may be assigned to more than one shelter, in any barangay.
                </p>
                <div class="roster-toolbar">
                    <input type="search" id="sh-staff-search" class="roster-search" placeholder="Filter staff by name" aria-label="Filter staff list">
                    <span class="roster-count" id="sh-staff-count">0 selected</span>
                </div>
                <div class="roster-list" id="sh-staff-list" role="group" aria-label="Assigned staff">
                    @forelse($staffPool as $s)
                        <label class="checkbox-row roster-row" data-staff-name="{{ strtolower($s->name) }}">
                            <input type="checkbox" name="staff[]" value="{{ $s->id }}">
                            <span class="roster-name">{{ $s->name }}</span>
                            <span class="roster-meta">{{ $s->barangay?->name ? 'Brgy. ' . $s->barangay->name : 'Unassigned' }}</span>
                        </label>
                    @empty
                        <p class="empty-note">No active barangay personnel accounts yet. Create them in User Management first.</p>
                    @endforelse
                </div>
            </fieldset>

            <fieldset class="member-fieldset">
                <legend>Facilities</legend>
                <label class="checkbox-row"><input type="checkbox" name="has_water_supply" value="1"> Water supply</label>
                <label class="checkbox-row"><input type="checkbox" name="has_medical_desk" value="1"> Medical desk</label>
                <label class="checkbox-row"><input type="checkbox" name="has_power" value="1"> Power</label>
                <label class="checkbox-row"><input type="checkbox" name="has_communal_kitchen" value="1"> Communal kitchen</label>
            </fieldset>

            <div class="modal-actions">
                <button type="button" class="btn-secondary" data-close-modal>Cancel</button>
                <button type="submit" class="btn-primary" id="shelterSubmit">Add Shelter</button>
            </div>
        </form>
    </div>
</div>
@endpush

@push('scripts')
<script>
    window.ShelterAdminConfig = {
        storeUrl: "{{ route('city.shelters.store') }}",
    };
</script>
@endpush
