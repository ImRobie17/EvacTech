@extends('layouts.cityadmin')

@php
    // PHASE 3 ITEM 10. Leaflet reaches this page ONLY through this entry, so the
    // rest of the City Admin screens still download no map code. See the header
    // of resources/js/shelter-picker.js for why it is a separate entry from
    // resources/js/map.js.
    $viteEntries = ['resources/js/shelter-picker.js'];

    // Reference pins: EVERY shelter that has coordinates, deliberately NOT the
    // filtered or paginated set. A picker showing 15 of 30 shelters would make
    // the city look emptier than it is and invite a duplicate in a gap that is
    // not really a gap. $mapShelters comes from ShelterController::index().
    $pickerData = collect($mapShelters ?? [])->map(fn ($s) => [
        'id' => $s->id,
        'name' => $s->name,
        'barangay_id' => $s->barangay_id,
        'lat' => (float) $s->latitude,
        'lng' => (float) $s->longitude,
    ])->values();

    // GOTCHA 1 and 2: built in a PHP block, never as an inline json directive
    // containing arrows, and emitted with the RAW echo into a data island. The
    // HEX flags mean a shelter named with a quote or an angle bracket cannot
    // reshape the surrounding markup.
    $pickerJson = json_encode($pickerData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
@endphp

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
    {{-- data-stack plus a data-label on every <td>: one change, never one
         without the other. Eight columns is the widest table in the City Admin
         screens and the one that scrolled worst on a phone. --}}
    <table class="data-table" data-stack>
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
                    <td data-label="Shelter">{{ $c->name }}</td>
                    <td data-label="Barangay">{{ $c->barangay?->name }}</td>
                    <td data-label="Location">{{ $c->address }}</td>
                    <td data-label="Capacity" data-numeric>{{ number_format($c->capacity) }}</td>
                    {{-- data-label is allowed to be shorter than its <th>: there
                         is less room on a phone and the full heading is still in
                         the accessibility tree. --}}
                    <td data-label="Occupancy" data-numeric class="{{ $capClass }}">
                        {{ number_format($c->current_occupancy) }}{{ $pct !== null ? " ({$pct}%)" : '' }}
                        @if ($c->isOvercapacity())
                            <small class="over-note">+{{ number_format($c->overBy()) }} over</small>
                        @endif
                    </td>
                    <td data-label="Staff">
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
                    <td data-label="Status">
                        @include('partials.status-badge', ['center' => $c])
                    </td>
                    <td class="actions-cell" data-label="Actions">
                        {{-- An anchor, not a button: the 44px floor in
                             design-system.css applies to button and input
                             elements, NOT to <a>, so the tap target is set
                             explicitly here. --}}
                        <a class="btn-link inline-flex min-h-tap items-center" href="{{ route('city.shelters.show', $c) }}">View Details</a>
                        <button type="button" class="btn-link" data-edit-shelter="{{ $editShelterJson }}">Edit</button>
                    </td>
                </tr>
            @empty
                {{-- The one documented exception to the data-label rule: a single
                     colspan cell has no column to be labelled with, and
                     staff.css exempts td.empty-note from the stacked treatment. --}}
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

            {{-- PHASE 3 ITEM 10 -- two columns: form on the left, map on the right,
                 so the map is visible for the whole time the modal is open.

                 ONE dialog, not two. A second modal beside this one would mean two
                 elements carrying aria-modal="true" at the same time, which no
                 screen reader can resolve, two competing focus traps, and two
                 backdrops where staff.js's backdrop-click handler would close one
                 and strand the other. It would also have no meaning at all on a
                 phone, where there is no "right hand side".

                 Default align-items (stretch) is deliberate: it makes this cell as
                 tall as the form beside it, which is what gives the sticky panel
                 inside it room to travel as the form scrolls. items-start would
                 collapse the cell and the sticky would never move. --}}
            <div class="grid grid-cols-1 gap-4 lg:grid-cols-2 lg:gap-6">
            <div class="min-w-0">

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

            {{-- Capacity is alone here now. Latitude and longitude moved into the
                 Location panel in the right-hand column, beside the map that
                 writes them, so the numbers sit next to the thing that produces
                 them instead of three columns away from it. --}}
            <div class="field"><label for="sh-capacity">Capacity (persons)</label><input type="number" id="sh-capacity" name="capacity" min="1" required></div>

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
                <div class="grid grid-cols-1 gap-1 sm:grid-cols-2">
                    <label class="checkbox-row"><input type="checkbox" name="has_water_supply" value="1"> Water supply</label>
                    <label class="checkbox-row"><input type="checkbox" name="has_medical_desk" value="1"> Medical desk</label>
                    <label class="checkbox-row"><input type="checkbox" name="has_power" value="1"> Power</label>
                    <label class="checkbox-row"><input type="checkbox" name="has_communal_kitchen" value="1"> Communal kitchen</label>
                </div>
            </fieldset>

            </div>{{-- end left column --}}

            {{-- ---- Right column: the location picker ---- --}}
            <div class="min-w-0">
                <div class="lg:sticky lg:top-2">
                    <fieldset class="member-fieldset">
                        <legend>Location on map</legend>
                        <p class="field-hint">
                            Click the map to place this shelter, then drag the pin to adjust it.
                            Grey dots are shelters that already have a location; their names appear
                            as you zoom in. Choosing a barangay above moves the map to it.
                        </p>

                        {{-- Leaflet needs a definite height or it renders nothing, and it
                             needs a rounded, clipped box or tiles bleed past the corners.
                             Utilities, not a new stylesheet class. --}}
                        {{-- role="group", not role="application". Application tells a
                             screen reader to hand every keystroke to the widget, which
                             would strand a keyboard user inside a map they cannot
                             operate. The coordinate fields below are the keyboard path,
                             and they are always present. --}}
                        <div id="shelterPickerMap"
                             class="h-72 w-full overflow-hidden rounded-md border border-border bg-surface lg:h-80"
                             role="group"
                             aria-label="Shelter location picker map"></div>

                        {{-- The state is announced in words, never by the pin's colour
                             alone, and aria-live means a screen reader hears each
                             placement. --}}
                        <p id="shelterPickerStatus" class="mt-2 text-sm text-ink-soft" role="status" aria-live="polite">
                            No location set. Click the map to place this shelter.
                        </p>

                        {{-- The coordinate fields stay real, visible and editable. They are
                             the read-out of the pin, the way to paste a surveyed figure, and
                             the only way to set a location without a mouse. --}}
                        <div class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2">
                            <div class="field"><label for="sh-lat">Latitude <small>(optional)</small></label><input type="number" step="any" id="sh-lat" name="latitude"></div>
                            <div class="field"><label for="sh-lng">Longitude <small>(optional)</small></label><input type="number" step="any" id="sh-lng" name="longitude"></div>
                        </div>

                        <button type="button" class="btn-link" id="shelterPickerClear" disabled>Clear location</button>

                        <p class="field-hint">
                            A shelter with no location is saved normally. It simply does not
                            appear on the public evacuation map until one is set.
                        </p>
                    </fieldset>
                </div>
            </div>

            </div>{{-- end two-column grid --}}

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

{{-- Data island, not a JS expression, exactly as the public map does it. The
     browser never parses this as code, so a shelter name containing a quote or a
     bracket is inert here. resources/js/shelter-picker.js reads it by id. --}}
<script type="application/json" id="shelterPickerData">{!! $pickerJson !!}</script>
@endpush
