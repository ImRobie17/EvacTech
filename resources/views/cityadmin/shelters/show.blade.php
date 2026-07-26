{{--
    City Admin's shelter detail page. City-Admin-only: there is no $layout
    switch, no $isCity branch and no role check anywhere in this file, because no
    other role can reach it. Same tables as the barangay screens, separate
    presentation.

    Every action posts to a city.shelters.* route bound to {center}, and every
    modal lives ON THIS PAGE -- Edit Family Group opens in place instead of
    navigating to Evacuee Profiling.
--}}
@extends('layouts.cityadmin')

@section('title', $center->name)
@section('page-title', $center->name)
@section('page-subtitle', ($center->barangay?->name ? 'Brgy. ' . $center->barangay->name . ' - ' : '') . $center->address)

@section('page-actions')
    @if ($tab === 'relief')
        <button type="button" class="btn-secondary" data-open-modal="cdReceiveModal">Receive Stock</button>
        <button type="button" class="btn-primary" data-open-modal="cdDistributeModal">Distribute Relief</button>
    @else
        <button type="button" class="btn-primary" data-open-modal="cdCheckinModal">Check-in Family</button>
    @endif
@endsection

@section('content')
<a href="{{ route('city.shelters.index') }}" class="btn-link back-link">&larr; Back to all shelters</a>

<section class="shelter-summary">
    @include('partials.capacity-panel', ['center' => $center])

    <article class="card panel">
        <h2 class="panel-title">Shelter Details</h2>
        <dl class="detail-list">
            <dt>Status</dt><dd>@include('partials.status-badge', ['center' => $center])</dd>
            <dt>Barangay</dt><dd>{{ $center->barangay?->name ?? '-' }}</dd>
            <dt>Assigned staff</dt>
            <dd>
                @if ($center->assignedStaff->isEmpty())
                    <span class="badge badge-warning">None assigned</span>
                @else
                    {{ $center->assignedStaff->pluck('name')->implode(', ') }}
                @endif
            </dd>
            <dt>Facilities</dt>
            <dd>
                @php
                    $facilities = collect([
                        'Water' => $center->has_water_supply,
                        'Medical desk' => $center->has_medical_desk,
                        'Power' => $center->has_power,
                        'Kitchen' => $center->has_communal_kitchen,
                    ])->filter()->keys();
                @endphp
                {{ $facilities->isEmpty() ? 'None recorded' : $facilities->implode(', ') }}
            </dd>
        </dl>
    </article>
</section>

{{-- ---- Tabs. Plain links with ?tab=, so back/forward and bookmarks work. ---- --}}
<nav class="tab-strip" aria-label="Shelter sections">
    <a href="{{ route('city.shelters.show', $center) }}?tab=households"
       class="tab-link {{ $tab === 'households' ? 'active' : '' }}"
       @if($tab === 'households') aria-current="page" @endif>Households</a>
    <a href="{{ route('city.shelters.show', $center) }}?tab=relief"
       class="tab-link {{ $tab === 'relief' ? 'active' : '' }}"
       @if($tab === 'relief') aria-current="page" @endif>Relief</a>
</nav>

@if ($tab === 'households')
    {{-- =============== HOUSEHOLDS TAB =============== --}}
    <form method="GET" class="filter-bar" role="search">
        <input type="hidden" name="tab" value="households">
        <input type="search" name="q" value="{{ request('q') }}" placeholder="Search household head" aria-label="Search household head">
        <select name="status" aria-label="Filter status">
            <option value="">All statuses</option>
            <option value="checked_in" @selected(request('status') === 'checked_in')>Checked in</option>
            <option value="checked_out" @selected(request('status') === 'checked_out')>Checked out</option>
            <option value="registered" @selected(request('status') === 'registered')>Registered</option>
        </select>
        <select name="barangay" aria-label="Filter origin barangay">
            <option value="">All origin barangays</option>
            @foreach($originBarangays as $b)
                <option value="{{ $b->id }}" @selected(request('barangay') == $b->id)>{{ $b->name }}</option>
            @endforeach
        </select>
        <select name="sort" aria-label="Sort">
            <option value="recent" @selected(request('sort') !== 'name')>Most recent</option>
            <option value="name" @selected(request('sort') === 'name')>Head name (A-Z)</option>
        </select>
        <button type="submit" class="btn-secondary">Filter</button>
    </form>

    <div class="card panel table-panel">
        <table class="data-table">
            <thead>
                <tr>
                    <th scope="col">Household ID</th>
                    <th scope="col">Household Head</th>
                    <th scope="col">Origin Barangay</th>
                    <th scope="col">Size</th>
                    <th scope="col">Present</th>
                    <th scope="col">Status</th>
                    <th scope="col">Checked In</th>
                    <th scope="col">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($households as $h)
                    <tr>
                        <td>{{ $h->household_code }}</td>
                        <td>{{ $h->headMember?->full_name ?? '-' }}</td>
                        <td>{{ $h->originBarangay?->name ?? '-' }}</td>
                        <td data-numeric>{{ $h->number_of_members }}</td>
                        <td data-numeric>{{ $h->members_present }}</td>
                        <td>
                            <span class="badge {{ $h->status === 'checked_in' ? 'badge-success' : 'badge-warning' }}">
                                {{ ucfirst(str_replace('_', ' ', $h->status)) }}
                            </span>
                        </td>
                        <td data-numeric>{{ $h->checked_in_at?->format('M d, Y - h:i A') ?? '-' }}</td>
                        <td class="actions-cell">
                            {{-- Opens the modal on THIS page. No navigation. --}}
                            <button type="button" class="btn-link"
                                    data-cd-edit-household="{{ $h->id }}">Edit Family Group</button>
                            @if ($h->status === 'checked_in')
                                <form method="POST" action="{{ route('city.shelters.households.checkout', [$center, $h]) }}"
                                      class="inline-form" data-confirm="Check out {{ $h->household_code }}?">
                                    @csrf
                                    <button class="btn-link btn-link-danger">Check out</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="empty-note">No households at this shelter yet. Use "Check-in Family" to add one.</td></tr>
                @endforelse
            </tbody>
        </table>
        {{ $households->links() }}
    </div>
@else
    {{-- =============== RELIEF TAB =============== --}}
    <section class="kpi-grid">
        <article class="card kpi-card">
            <span class="kpi-label">Stock on hand</span>
            <p class="kpi-value" data-numeric>{{ number_format($stats['remaining']) }}</p>
        </article>
        <article class="card kpi-card">
            <span class="kpi-label">Total received</span>
            <p class="kpi-value" data-numeric>{{ number_format($stats['received']) }}</p>
        </article>
        <article class="card kpi-card">
            <span class="kpi-label">Total distributed</span>
            <p class="kpi-value" data-numeric>{{ number_format($stats['distributed']) }}</p>
        </article>
        <article class="card kpi-card">
            <span class="kpi-label">Est. days of stock</span>
            <p class="kpi-value" data-numeric>{{ $stats['days_left'] ?? '-' }}</p>
            <span class="kpi-note">7-day average burn rate. An estimate, not a promise.</span>
        </article>
    </section>

    <div class="card panel table-panel">
        <h2 class="panel-title">Current Inventory</h2>
        <table class="data-table">
            <thead>
                <tr><th scope="col">Relief Good</th><th scope="col">On Hand</th><th scope="col">Reorder Level</th><th scope="col">Stock</th></tr>
            </thead>
            <tbody>
                @forelse($inventory as $inv)
                    @php
                        $low = $inv->reorder_level > 0 && $inv->quantity_on_hand <= $inv->reorder_level;
                    @endphp
                    <tr>
                        <td>{{ $inv->reliefGood?->name }}</td>
                        <td data-numeric>{{ number_format($inv->quantity_on_hand) }} {{ $inv->reliefGood?->unit }}</td>
                        <td data-numeric>{{ number_format($inv->reorder_level) }}</td>
                        <td>
                            @if ($inv->quantity_on_hand <= 0)
                                <span class="badge badge-danger">Out of stock</span>
                            @elseif ($low)
                                <span class="badge badge-warning">Low</span>
                            @else
                                <span class="badge badge-success">OK</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="empty-note">No inventory recorded. Use "Receive Stock" to add some.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <form method="GET" class="filter-bar" role="search">
        <input type="hidden" name="tab" value="relief">
        <input type="search" name="q" value="{{ request('q') }}" placeholder="Search household head" aria-label="Search distribution log">
        <button type="submit" class="btn-secondary">Filter</button>
    </form>

    <div class="card panel table-panel">
        <h2 class="panel-title">Distribution Log</h2>
        <table class="data-table">
            <thead>
                <tr><th scope="col">Date</th><th scope="col">Household</th><th scope="col">Item</th><th scope="col">Qty</th><th scope="col">Recorded By</th><th scope="col">Remarks</th></tr>
            </thead>
            <tbody>
                @forelse($log as $t)
                    <tr>
                        <td data-numeric>{{ $t->transaction_date?->format('M d, Y') }}</td>
                        <td>{{ $t->household?->headMember?->full_name ?? '-' }}</td>
                        <td>{{ $t->reliefGood?->name }}</td>
                        <td data-numeric>{{ number_format($t->quantity) }} {{ $t->reliefGood?->unit }}</td>
                        <td>{{ $t->recordedBy?->name ?? '-' }}</td>
                        <td>{{ $t->remarks }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="empty-note">No distributions logged at this shelter yet.</td></tr>
                @endforelse
            </tbody>
        </table>
        {{ $log->links() }}
    </div>
@endif
@endsection

@push('modals')
{{-- ======== Check-in Family (search, then pick who is present) ======== --}}
<div class="modal-backdrop" id="cdCheckinModal" hidden>
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="cdCheckinTitle">
        <div class="modal-head">
            <h2 id="cdCheckinTitle">Check-in Family</h2>
            <button type="button" class="icon-btn" data-close-modal aria-label="Close">&times;</button>
        </div>

        <div class="field search-inline">
            <label for="cd-ci-search">Household head name</label>
            <input type="search" id="cd-ci-search" placeholder="Search registered household" autocomplete="off">
            <ul class="search-results" id="cd-ci-results" hidden></ul>
        </div>

        <form method="POST" id="cdCheckinForm" hidden>
            @csrf
            <div class="ci-profile">
                <p><strong id="cd-ci-code"></strong> &middot; <span id="cd-ci-head"></span></p>
                <p class="kpi-note" id="cd-ci-origin"></p>
            </div>
            <fieldset class="member-fieldset">
                <legend>Who is present?</legend>
                <div id="cd-ci-members"></div>
            </fieldset>
            <div class="modal-actions">
                <button type="button" class="btn-secondary" data-close-modal>Cancel</button>
                <button type="submit" class="btn-primary">Confirm Check-in</button>
            </div>
        </form>
    </div>
</div>

{{-- ======== Edit Family Group -- opens IN PLACE on this page ======== --}}
<div class="modal-backdrop" id="cdEditModal" hidden>
    <div class="modal modal-wide" role="dialog" aria-modal="true" aria-labelledby="cdEditTitle">
        <div class="modal-head">
            <h2 id="cdEditTitle">Edit Family Group</h2>
            <button type="button" class="icon-btn" data-close-modal aria-label="Close">&times;</button>
        </div>
        <form method="POST" id="cdEditForm">
            @csrf
            <input type="hidden" name="_method" value="PUT">
            <div class="member-grid">
                <div class="field">
                    <label for="cd-ed-barangay">Origin barangay</label>
                    <select id="cd-ed-barangay" name="origin_barangay_id" required>
                        <option value="">Select barangay</option>
                        @foreach($originBarangays ?? [] as $b)
                            <option value="{{ $b->id }}">{{ $b->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="cd-ed-address">Family address</label>
                    <input type="text" id="cd-ed-address" name="address" required maxlength="255">
                </div>
            </div>

            <fieldset class="member-fieldset">
                <legend>Household Head</legend>
                <div id="cd-ed-head"></div>
            </fieldset>

            <fieldset class="member-fieldset">
                <legend>Family Members</legend>
                <div id="cd-ed-members"></div>
                <button type="button" class="btn-secondary" id="cdAddMemberBtn">+ Add family member</button>
            </fieldset>

            <div class="modal-actions">
                <button type="button" class="btn-secondary" data-close-modal>Cancel</button>
                <button type="submit" class="btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

{{-- ======== Distribute Relief ======== --}}
<div class="modal-backdrop" id="cdDistributeModal" hidden>
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="cdDistTitle">
        <div class="modal-head">
            <h2 id="cdDistTitle">Distribute Relief</h2>
            <button type="button" class="icon-btn" data-close-modal aria-label="Close">&times;</button>
        </div>

        <div class="field search-inline">
            <label for="cd-dist-search">Household head name</label>
            <input type="search" id="cd-dist-search" placeholder="Search checked-in household" autocomplete="off">
            <ul class="search-results" id="cd-dist-results" hidden></ul>
        </div>

        <form method="POST" action="{{ route('city.shelters.relief.distribute', $center) }}" id="cdDistributeForm" hidden>
            @csrf
            <input type="hidden" name="household_id" id="cd-dist-household-id">
            <div class="ci-profile">
                <p><strong id="cd-dist-code"></strong> &middot; <span id="cd-dist-head"></span></p>
            </div>
            <div id="cd-dist-items">
                <div class="dist-item-row">
                    <select name="items[0][relief_good_id]" required aria-label="Relief good">
                        <option value="">Select item</option>
                        @foreach($goods ?? [] as $g)
                            <option value="{{ $g->id }}">{{ $g->name }} ({{ $g->unit }})</option>
                        @endforeach
                    </select>
                    <input type="number" name="items[0][quantity]" min="1" value="1" required aria-label="Quantity">
                </div>
            </div>
            <button type="button" class="btn-link" id="cdAddItemBtn">+ Add another item</button>

            <div class="field">
                <label for="cd-dist-remarks">Remarks <small>(optional)</small></label>
                <textarea id="cd-dist-remarks" name="remarks" rows="2" maxlength="500"></textarea>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-secondary" data-close-modal>Cancel</button>
                <button type="submit" class="btn-primary">Log Distribution</button>
            </div>
        </form>
    </div>
</div>

{{-- ======== Receive Stock ======== --}}
<div class="modal-backdrop" id="cdReceiveModal" hidden>
    <div class="modal modal-narrow" role="dialog" aria-modal="true" aria-labelledby="cdRecvTitle">
        <div class="modal-head">
            <h2 id="cdRecvTitle">Receive Stock</h2>
            <button type="button" class="icon-btn" data-close-modal aria-label="Close">&times;</button>
        </div>
        <form method="POST" action="{{ route('city.shelters.relief.receive', $center) }}">
            @csrf
            <div class="field">
                <label for="cd-recv-good">Relief good</label>
                <select id="cd-recv-good" name="relief_good_id" required>
                    <option value="">Select item</option>
                    @foreach($goods ?? [] as $g)
                        <option value="{{ $g->id }}">{{ $g->name }} ({{ $g->unit }})</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="cd-recv-qty">Quantity</label>
                <input type="number" id="cd-recv-qty" name="quantity" min="1" required>
            </div>
            <div class="field">
                <label for="cd-recv-source">Source <small>(optional)</small></label>
                <input type="text" id="cd-recv-source" name="source" maxlength="255">
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-secondary" data-close-modal>Cancel</button>
                <button type="submit" class="btn-primary">Add to Inventory</button>
            </div>
        </form>
    </div>
</div>

{{-- Member row template for the Edit Family Group modal --}}
<template id="cdMemberRowTemplate">
    <div class="member-row" data-row>
        <input type="hidden" data-field="id" name="">
        <div class="member-grid">
            <div class="field"><label>Last name</label><input type="text" data-field="last_name" required maxlength="100"></div>
            <div class="field"><label>First name</label><input type="text" data-field="first_name" required maxlength="100"></div>
            <div class="field"><label>Middle name <small>(optional)</small></label><input type="text" data-field="middle_name" maxlength="100"></div>
            <div class="field"><label>Date of birth</label><input type="date" data-field="birthdate" required max="{{ now()->toDateString() }}"></div>
            <div class="field"><label>Sex</label>
                <select data-field="sex" required>
                    <option value="">Select</option>
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
    window.CityShelterConfig = {
        checkinSearchUrl: "{{ route('city.shelters.households.search', $center) }}",
        householdUrlTemplate: "{{ route('city.shelters.households.show', [$center, ':id']) }}",
        checkinUrlTemplate: "{{ route('city.shelters.households.checkin', [$center, ':id']) }}",
        updateUrlTemplate: "{{ route('city.shelters.households.update', [$center, ':id']) }}",
        reliefSearchUrl: "{{ route('city.shelters.relief.recipients', $center) }}",
        goodsOptions: {!! json_encode(($goods ?? collect())->map(fn ($g) => ['id' => $g->id, 'label' => $g->name . ' (' . $g->unit . ')'])->values()) !!},
    };
</script>
@endpush
