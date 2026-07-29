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

@php
    // PHASE 2 ITEM 8. Route templates for the shared transfer partials. Built in
    // a PHP block, printed with json_encode at the bottom (gotcha 1).
    $tx = [
        'store' => route('city.transfers.store'),
        'search' => route('city.transfers.households'),
        'members' => route('city.transfers.members', ':id'),
        'confirm' => route('city.transfers.confirm', ':id'),
        'refuse' => route('city.transfers.refuse', ':id'),
        'depart' => route('city.transfers.depart', ':id'),
        'receive' => route('city.transfers.receive', ':id'),
        'cancel' => route('city.transfers.cancel', ':id'),
    ];
    $txConfig = $tx;
    $txConfig['centers'] = $transferCenters ?? [];
    $txOpenIds = $openTransferHouseholdIds ?? [];
@endphp

@section('page-actions')
    @if ($tab === 'relief')
        <button type="button" class="btn-secondary" data-open-modal="cdReceiveModal">Receive Stock</button>
        <button type="button" class="btn-primary" data-open-modal="cdDistributeModal">Distribute Relief</button>
    @else
        <button type="button" class="btn-primary" data-open-modal="cdCheckinModal">Check-in Family</button>
    @endif
@endsection

@section('content')
{{-- An anchor, so the 44px tap floor is set here: design-system.css enforces it
     on button and input elements, not on <a>. --}}
<a href="{{ route('city.shelters.index') }}" class="btn-link back-link inline-flex min-h-tap items-center">&larr; Back to all shelters</a>

{{-- Two summary cards side by side from 1024px. .shelter-summary already does
     an auto-fit grid; the explicit breakpoint stops a 20rem minimum from
     dropping to one column at sizes where two still fit comfortably. --}}
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
        <table class="data-table" data-stack>
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
                        <td data-label="Household ID" data-numeric>{{ $h->household_code }}</td>
                        <td data-label="Head">{{ $h->headMember?->full_name ?? '-' }}</td>
                        <td data-label="Origin">{{ $h->originBarangay?->name ?? '-' }}</td>
                        <td data-label="Size" data-numeric>{{ $h->number_of_members }}</td>
                        <td data-label="Present" data-numeric>{{ $h->members_present }}</td>
                        <td data-label="Status">
                            <span class="badge {{ $h->status === 'checked_in' ? 'badge-success' : 'badge-warning' }}">
                                {{ ucfirst(str_replace('_', ' ', $h->status)) }}
                            </span>
                        </td>
                        <td data-label="Checked In" data-numeric>{{ $h->checked_in_at?->format('M d, Y - h:i A') ?? '-' }}</td>
                        <td class="actions-cell" data-label="Actions">
                            {{-- Opens the modal on THIS page. No navigation. --}}
                            <button type="button" class="btn-link"
                                    data-cd-edit-household="{{ $h->id }}">Edit Family Group</button>
                            @if ($h->status === 'checked_in')
                                {{-- PHASE 2 ITEM 8 -- shelter-to-shelter move. --}}
                                @if (in_array($h->id, $txOpenIds))
                                    <span class="text-sm text-ink-muted">Transfer in progress</span>
                                @else
                                    <button type="button" class="btn-link"
                                            data-tx-create
                                            data-household-id="{{ $h->id }}"
                                            data-household-code="{{ $h->household_code }}"
                                            data-household-head="{{ $h->headMember?->full_name }}"
                                            data-household-present="{{ $h->members_present }}"
                                            data-center-id="{{ $center->id }}"
                                            data-center-name="{{ $center->name }}">Move to Shelter</button>
                                @endif
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
    {{-- These four cards used class="kpi-label" on their labels. That class is
         defined in NO stylesheet -- not staff.css, not design-system.css, not
         cityadmin.css -- so all four rendered as unstyled text while every other
         KPI card in the app uses the .kpi-head / .kpi-title pattern. Now they
         match. --}}
    <section class="mb-4 grid grid-cols-1 items-stretch gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <article class="card kpi-card">
            <div class="kpi-head"><span class="kpi-title">Stock on hand</span></div>
            <p class="kpi-value" data-numeric>{{ number_format($stats['remaining']) }}</p>
        </article>
        <article class="card kpi-card">
            <div class="kpi-head"><span class="kpi-title">Total received</span></div>
            <p class="kpi-value" data-numeric>{{ number_format($stats['received']) }}</p>
        </article>
        <article class="card kpi-card">
            <div class="kpi-head"><span class="kpi-title">Total distributed</span></div>
            <p class="kpi-value" data-numeric>{{ number_format($stats['distributed']) }}</p>
        </article>
        <article class="card kpi-card">
            <div class="kpi-head"><span class="kpi-title">Est. days of stock</span></div>
            <p class="kpi-value" data-numeric>{{ $stats['days_left'] ?? '-' }}</p>
            <p class="kpi-note">7-day average burn rate. An estimate, not a promise.</p>
        </article>
    </section>

    <div class="card panel table-panel">
        <h2 class="panel-title">Current Inventory</h2>
        <table class="data-table" data-stack>
            <thead>
                <tr><th scope="col">Relief Good</th><th scope="col">On Hand</th><th scope="col">Reorder Level</th><th scope="col">Stock</th></tr>
            </thead>
            <tbody>
                @forelse($inventory as $inv)
                    @php
                        $low = $inv->reorder_level > 0 && $inv->quantity_on_hand <= $inv->reorder_level;
                    @endphp
                    <tr>
                        <td data-label="Relief Good">{{ $inv->reliefGood?->name }}</td>
                        <td data-label="On Hand" data-numeric>{{ number_format($inv->quantity_on_hand) }} {{ $inv->reliefGood?->unit }}</td>
                        <td data-label="Reorder Level" data-numeric>{{ number_format($inv->reorder_level) }}</td>
                        <td data-label="Stock">
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
        <table class="data-table" data-stack>
            <thead>
                <tr><th scope="col">Date</th><th scope="col">Household</th><th scope="col">Item</th><th scope="col">Qty</th><th scope="col">Recorded By</th><th scope="col">Remarks</th></tr>
            </thead>
            <tbody>
                @forelse($log as $t)
                    <tr>
                        <td data-label="Date" data-numeric>{{ $t->transaction_date?->format('M d, Y') }}</td>
                        <td data-label="Household">{{ $t->household?->headMember?->full_name ?? '-' }}</td>
                        <td data-label="Item">{{ $t->reliefGood?->name }}</td>
                        <td data-label="Qty" data-numeric>{{ number_format($t->quantity) }} {{ $t->reliefGood?->unit }}</td>
                        <td data-label="Recorded By">{{ $t->recordedBy?->name ?? '-' }}</td>
                        <td data-label="Remarks">{{ $t->remarks }}</td>
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
                {{-- The first row now carries a Remove control and data-item-row,
                     exactly like the template below.

                     It previously had neither. Rows added by "+ Add another item"
                     got a Remove button; this one never did, so an item picked by
                     mistake could only be undone by closing the modal and
                     starting over. .dist-item-row is `2fr 1fr auto` above 640px,
                     so it also left an empty third column beside every first row.

                     The control is disabled while only one row remains: an empty
                     items list fails validation server side with a message that
                     would not explain itself. --}}
                <div class="dist-item-row" data-item-row>
                    <select name="items[0][relief_good_id]" required aria-label="Relief good">
                        <option value="">Select item&hellip;</option>
                        @foreach($goods ?? [] as $g)
                            <option value="{{ $g->id }}">{{ $g->name }} ({{ $g->unit }})</option>
                        @endforeach
                    </select>
                    <input type="number" name="items[0][quantity]" min="1" value="1" required aria-label="Quantity">
                    <button type="button" class="btn-link btn-link-danger" data-remove-item aria-label="Remove this item" disabled>&times; Remove</button>
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

{{-- Template for extra distribution rows. Replaces building the row imperatively
     in cityadmin-shelter.js from CityShelterConfig.goodsOptions: rendering it
     here from the same $goods collection means the added rows cannot drift out
     of step with the first one. __INDEX__ is substituted in cityadmin-shelter.js. --}}
<template id="cdDistItemTemplate">
    <div class="dist-item-row" data-item-row>
        <select name="items[__INDEX__][relief_good_id]" required aria-label="Relief good">
            <option value="">Select item&hellip;</option>
            @foreach($goods ?? [] as $g)
                <option value="{{ $g->id }}">{{ $g->name }} ({{ $g->unit }})</option>
            @endforeach
        </select>
        <input type="number" name="items[__INDEX__][quantity]" min="1" value="1" required aria-label="Quantity">
        <button type="button" class="btn-link btn-link-danger" data-remove-item aria-label="Remove this item">&times; Remove</button>
    </div>
</template>

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
                    <option value="">Select item&hellip;</option>
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
            {{-- Birthdate is OPTIONAL as of Phase 2. --}}
            <div class="field"><label>Date of birth <small>(optional)</small></label><input type="date" data-field="birthdate" max="{{ now()->toDateString() }}"></div>
            <div class="field"><label>Sex</label>
                <select data-field="sex" required>
                    <option value="">Select</option>
                    <option value="male">Male</option>
                    <option value="female">Female</option>
                </select>
            </div>
        </div>

        <div class="mt-3 grid grid-cols-1 gap-4 md:grid-cols-[minmax(0,17rem)_minmax(0,1fr)]">
            {{-- Locked by JS when a date of birth is present: the server derives
                 the tier from the birthday and ignores this value. --}}
            <div class="field">
                <label>Age group</label>
                <select data-field="age_group" required>
                    <option value="">Select</option>
                    @foreach($ageGroups as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
                <small class="field-hint">Set automatically from the date of birth. Choose it here when the birthday is not known yet.</small>
            </div>

            <fieldset class="min-w-0 border-0 p-0">
                <legend class="mb-1 text-sm font-semibold">Vulnerable categories <small class="font-normal">(optional, choose any)</small></legend>
                <div class="flex flex-wrap gap-x-5 gap-y-1">
                    @foreach($classifications as $c)
                        <label class="inline-flex min-h-[44px] cursor-pointer items-center gap-2 text-sm">
                            <input type="checkbox" data-field="tags" value="{{ $c->id }}" class="h-5 w-5 shrink-0">
                            <span>{{ $c->name }}</span>
                        </label>
                    @endforeach
                </div>
            </fieldset>
        </div>

        <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
            <span class="age-tag badge badge-info" data-age-tag hidden></span>
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

@push('modals')
    {{-- PHASE 2 ITEM 8 -- shared transfer modals. --}}
    @include('partials.transfer-modals', ['tx' => $tx])
@endpush

@push('scripts')
<script>
    // Raw echo, not the escaped one. Blade's escaped echo runs the value through
    // e(), which turns every double quote in the JSON into an HTML entity. A
    // browser does not decode entities inside a script tag, so the assignment
    // threw a SyntaxError and window.TransferConfig was never set. JSON headed
    // for a script block always needs the raw echo.
    //
    // And note there is no Blade echo syntax anywhere in this comment: a JS
    // comment is still Blade source, so braces here would be compiled and would
    // break the whole file. Same trap as naming a Blade directive in a comment.
    window.TransferConfig = {!! json_encode($txConfig) !!};
</script>
@endpush
