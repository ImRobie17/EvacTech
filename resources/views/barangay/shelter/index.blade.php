@extends('layouts.staff')

@section('title', 'Evacuation Shelter')
@section('page-title', 'Evacuation Shelter')
@section('page-subtitle', $center?->name ?? 'No assigned shelter')

@php
    // PHASE 2 ITEM 8. Route templates for the shared transfer partials, built in
    // a PHP block and printed with json_encode further down: a json directive holding
    // arrows or spanning lines does not survive Blade's parser (gotcha 1).
    $tx = [
        'store' => route('barangay.transfers.store'),
        'search' => route('barangay.transfers.households'),
        'members' => route('barangay.transfers.members', ':id'),
        'confirm' => route('barangay.transfers.confirm', ':id'),
        'refuse' => route('barangay.transfers.refuse', ':id'),
        'depart' => route('barangay.transfers.depart', ':id'),
        'receive' => route('barangay.transfers.receive', ':id'),
        'cancel' => route('barangay.transfers.cancel', ':id'),
    ];
    $txConfig = $tx;
    $txConfig['centers'] = $transferCenters ?? [];
    $txOpenIds = $openTransferHouseholdIds ?? [];
@endphp

@section('page-actions')
    <button type="button" class="btn-primary w-full sm:w-auto" data-open-modal="checkinModal">&check; Check-in Existing Family</button>
@endsection

@section('content')
@php
    $pct = $center && $center->capacity > 0 ? round($center->current_occupancy / $center->capacity * 100) : null;
    // Band comes from the model so the thresholds live in one place.
    $capClass = $center ? 'cap-' . $center->capacityBand() : '';
@endphp

@unless($center)
    <div class="alert alert-warning">No shelter selected. Choose one from the switcher above, or ask your Evacuation Administrator to assign you to a shelter.</div>
@else
<section class="grid grid-cols-1 items-start gap-4 lg:grid-cols-3">
    <div class="lg:col-span-2">
        {{-- Left unconverted on purpose: partials/capacity-panel is also included
             by cityadmin/shelters/show, which Chat C has not converted. It is
             styled entirely by staff.css classes, so it renders exactly as
             before. Chat C owns its conversion. --}}
        @include('partials.capacity-panel', ['center' => $center])

        <form method="GET" class="filter-bar sm:grid sm:grid-cols-2 sm:items-end lg:grid-cols-4" role="search">
            <input type="search" name="q" value="{{ request('q') }}" placeholder="Search household head&hellip;" aria-label="Search household head name">
            <select name="status" aria-label="Filter status">
                <option value="">All statuses</option>
                <option value="checked_in" @selected(request('status') === 'checked_in')>Checked in</option>
                <option value="checked_out" @selected(request('status') === 'checked_out')>Checked out</option>
                <option value="transferred" @selected(request('status') === 'transferred')>Transferred</option>
            </select>
            <select name="sort" aria-label="Sort">
                <option value="recent" @selected(request('sort', 'recent') === 'recent')>Most recent</option>
                <option value="name" @selected(request('sort') === 'name')>Name (A-Z)</option>
            </select>
            <button type="submit" class="btn-secondary">Apply</button>
        </form>

        <div class="card panel table-panel">
            {{-- data-stack: below 768px this table becomes labelled cards rather
                 than a horizontally scrolling grid. Every <td> below therefore
                 carries a data-label -- adding the attribute without the labels
                 would print a 40% blank gutter down the left of every card.
                 The two go together, always. --}}
            <table class="data-table" data-stack>
                <thead>
                    <tr>
                        <th scope="col">Household Head</th>
                        <th scope="col">Family Size</th>
                        <th scope="col">Check-in Date &amp; Time</th>
                        <th scope="col">Status</th>
                        <th scope="col">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($households as $h)
                        <tr>
                            <td data-label="Household Head">{{ $h->headMember?->full_name ?? '-' }}</td>
                            <td data-label="Family Size" data-numeric>{{ $h->members_present }} / {{ $h->number_of_members }}</td>
                            <td data-label="Check-in" data-numeric>{{ $h->checked_in_at?->format('M d, Y - h:i A') ?? '-' }}</td>
                            <td data-label="Status">
                                <span class="badge {{ $h->status === 'checked_in' ? 'badge-success' : ($h->status === 'checked_out' ? 'badge-warning' : 'badge-info') }}">
                                    {{ ucfirst(str_replace('_', ' ', $h->status)) }}
                                </span>
                            </td>
                            <td class="actions-cell" data-label="Actions">
                                {{-- Was a <button data-open-modal="evacueeEditRedirect">
                                     with an inline onclick doing the actual work.
                                     No modal with that id exists anywhere, so the
                                     data attribute registered a listener that
                                     opened nothing, and the navigation happened
                                     only because of the onclick beside it. It is
                                     a link, so it is written as a link: keyboard
                                     reachable, middle-clickable, and honest about
                                     leaving the page.

                                     It still navigates to Evacuee Profiling rather
                                     than editing in place. Bringing the evacuee
                                     modal here needs $classifications, $barangays
                                     and $defaultBarangayId from ShelterController
                                     plus a duplicate of the member-row template --
                                     deliberately deferred to Phase 1 item 1, which
                                     reshapes shelter routing anyway. --}}
                                {{-- inline-flex + min-h-tap because the 44px floor
                                     in design-system.css is applied to button and
                                     input elements, not to anchors. --}}
                                <a class="btn-link inline-flex min-h-tap items-center"
                                   href="{{ route('barangay.evacuees.index') }}?edit={{ $h->id }}">Edit Family Group</a>
                                @if($h->status === 'checked_in')
                                    {{-- PHASE 2 ITEM 8. Named "Move to Shelter", not
                                         "Transfer": the Transfer Head flow already
                                         lives on this page and two unrelated
                                         controls called Transfer is how a demo
                                         goes wrong. --}}
                                    @if(in_array($h->id, $txOpenIds))
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
                                    <form method="POST" action="{{ route('barangay.shelter.checkout', $h) }}" class="inline-form"
                                          data-confirm="Check out {{ $h->headMember?->full_name }}'s household?">
                                        @csrf
                                        <button type="submit" class="btn-link btn-link-danger">Check-out</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        {{-- No data-label: a colspan cell has no column to name.
                             staff.css exempts td.empty-note from the stacked
                             label treatment for exactly this row. --}}
                        <tr><td colspan="5" class="empty-note">No households at this shelter match your search.</td></tr>
                    @endforelse
                </tbody>
            </table>
            @if($households instanceof \Illuminate\Pagination\AbstractPaginator)
                {{ $households->links() }}
            @endif
        </div>
    </div>

    <div class="flex flex-col">
        <h2 class="panel-title">Recent Activity</h2>
        <div class="card panel activity-panel">
            @forelse($recent as $event)
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-border p-3 text-sm last:border-b-0">
                    <span class="badge {{ $event['type'] === 'check_in' ? 'badge-success' : 'badge-warning' }}">
                        {{ $event['type'] === 'check_in' ? 'Check-in' : 'Check-out' }}
                    </span>
                    <span class="min-w-0 flex-1 font-medium">{{ $event['household']->headMember?->full_name ?? $event['household']->household_code }}</span>
                    <time class="text-ink-muted" datetime="{{ $event['at']->toIso8601String() }}">{{ $event['at']->diffForHumans() }}</time>
                </div>
            @empty
                <p class="empty-note">No activity yet.</p>
            @endforelse
        </div>
    </div>
</section>
@endunless
@endsection

@push('modals')
{{-- ======== Check-in Family modal ======== --}}
<div class="modal-backdrop" id="checkinModal" hidden>
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="checkinTitle">
        <div class="modal-head">
            <h2 id="checkinTitle">Check-in Family</h2>
            <button type="button" class="icon-btn" data-close-modal aria-label="Close">&times;</button>
        </div>

        <div class="field search-inline">
            <label for="ci-search">Household head name</label>
            <div class="flex flex-col gap-2 sm:flex-row">
                <input type="search" id="ci-search" class="sm:flex-1" placeholder="e.g. Dela Cruz, Juan" autocomplete="off">
                <button type="button" class="btn-secondary" id="ci-load">Load Profile</button>
            </div>
            <ul class="search-results" id="ci-results" hidden></ul>
        </div>

        <form method="POST" id="checkinForm" hidden>
            @csrf
            <div class="ci-profile">
                <p><strong id="ci-code"></strong> &middot; <span id="ci-head"></span></p>
                <p class="kpi-note">Tick everyone who is present at the shelter right now:</p>
                <div id="ci-members" class="checkbox-list"></div>
            </div>
            {{-- Stacks below 640px with the primary action last, so a thumb
                 reaching the bottom of the sheet lands on Confirm, not Cancel. --}}
            <div class="modal-actions flex flex-col gap-2 sm:flex-row sm:items-center">
                <button type="button" class="btn-link" id="ci-edit-family">Edit Family Group</button>
                <span class="hidden sm:block sm:flex-1"></span>
                <button type="button" class="btn-secondary" data-close-modal>Cancel</button>
                <button type="submit" class="btn-primary">Confirm Check-in</button>
            </div>
        </form>
    </div>
</div>

{{-- ======== Select New Family Head modal ======== --}}
<div class="modal-backdrop" id="transferHeadModal" hidden>
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="transferTitle">
        <div class="modal-head">
            <h2 id="transferTitle">Select New Family Head</h2>
            <button type="button" class="icon-btn" data-close-modal aria-label="Close">&times;</button>
        </div>
        <p>Current head: <strong id="th-current"></strong></p>
        <div id="th-options" class="radio-list" role="radiogroup" aria-label="Select the new household head"></div>
        <div class="modal-actions flex flex-col gap-2 sm:flex-row sm:justify-end">
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
            <div class="modal-actions flex flex-col gap-2 sm:flex-row sm:justify-end">
                <button type="button" class="btn-secondary" data-close-modal>Cancel</button>
                <button type="submit" class="btn-danger">Confirm Transfer</button>
            </div>
        </form>
    </div>
</div>
@endpush

@push('scripts')
<script>
    window.ShelterConfig = {
        searchUrl: "{{ route('barangay.evacuees.search') }}",
        showUrlTemplate: "{{ route('barangay.evacuees.show', ':id') }}",
        checkinUrlTemplate: "{{ route('barangay.shelter.checkin', ':id') }}",
        transferUrlTemplate: "{{ route('barangay.shelter.transfer', ':id') }}",
        editRedirectTemplate: "{{ route('barangay.evacuees.index') }}?edit=:id",
        autoOpen: @json(request('open') === 'checkin'),
    };
</script>
@endpush

@push('modals')
    {{-- PHASE 2 ITEM 8 -- the same four modals the Transfers page uses. --}}
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
