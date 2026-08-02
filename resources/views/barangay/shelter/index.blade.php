@extends('layouts.staff')

@section('title', 'Evacuation Shelter')
@section('page-title', 'Evacuation Shelter')
{{-- PHASE 6 ITEM 7. Was the shelter name, which .page-head now prints one
     line above. Two copies of the same string is not context, it is noise. --}}
@section('page-subtitle', 'Check families in and out, and monitor occupancy.')

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
        // PHASE 5 ITEM 8b. No Resolve button on this page, but the shared
        // transfer-modals partial carries the Resolve modal, so the template
        // is supplied rather than leaving a half-configured modal behind.
        'resolve' => route('barangay.transfers.resolve', ':id'),
    ];
    // PHASE 5 ITEM 8b. Presence correction rides on the SAME config object as
    // the transfer modals, so this page still contains exactly one raw JSON
    // echo. A second echo would be a second place for the escaped-echo bug
    // (gotcha 2) to come back. Not added to $tx itself: that array is passed to
    // partials/transfer-table, which has no use for these two.
    $txConfig = $tx;
    $txConfig['presence'] = route('barangay.shelter.presence', ':id');
    $txConfig['presenceSave'] = route('barangay.shelter.presence.update', ':id');
    $txConfig['centers'] = $transferCenters ?? [];
    $txOpenIds = $openTransferHouseholdIds ?? [];
@endphp

@section('page-actions')
    {{-- PHASE 3 ITEM 9. A link, not a second copy of the registration modal.
         The evacuees page already auto-opens its Register modal when the query
         string carries open=register -- EvacueeConfig.autoOpen reads exactly
         that -- so this needs no controller data, no duplicated member-row
         template, and no second place for the tags[] input naming to go wrong.
         Same pattern as the existing ?edit={id} link into that page.

         An anchor, not a button: the 44px floor in design-system.css applies to
         button and input elements, NOT to <a>, so the tap target is set here. --}}
    <a class="btn-secondary inline-flex min-h-tap w-full items-center justify-center sm:w-auto"
       href="{{ route('barangay.evacuees.index', ['open' => 'register']) }}">+ Register New Household</a>
    <button type="button" class="btn-primary w-full sm:w-auto" data-open-modal="checkinModal">&check; Check-in Existing Family</button>
@endsection

@section('content')
@php
    $pct = $center && $center->capacity > 0 ? round($center->current_occupancy / $center->capacity * 100) : null;
    // Band comes from the model so the thresholds live in one place.
    $capClass = $center ? 'cap-' . $center->capacityBand() : '';
@endphp

@unless($center)
    {{-- PHASE 6 ITEM 7. Used to read "Choose one from the switcher above". That
         switcher is gone, and pointing at a control that no longer exists is
         worse than no instruction at all. Assignment is a City Admin action. --}}
    <div class="alert alert-warning">No shelter assigned to this account. Ask your Evacuation Administrator to assign you to a shelter.</div>
@else
{{-- PHASE 6 ITEM 1 / G. The table used to live in the left 2/3 of this grid,
     which on a 1920px screen gave five columns roughly 800px to share: the
     check-in timestamp wrapped to six lines and the actions cell overflowed the
     panel. Recent Activity, capped at five events, was taller than the capacity
     panel beside it, so the old single-row layout also left a dead band beneath
     whichever card finished first.

     Now: ROW 1 carries the capacity panel and the filter bar in the left two
     columns and Recent Activity in the third, which balances the two heights.
     ROW 2 is the table at FULL width. Two sibling elements, not one grid --
     the table is deliberately outside the grid so nothing constrains it. --}}
<section class="grid grid-cols-1 items-start gap-4 lg:grid-cols-3">
    <div class="lg:col-span-2">
        {{-- Left unconverted on purpose: partials/capacity-panel is also included
             by cityadmin/shelters/show, which Chat C has not converted. It is
             styled entirely by staff.css classes, so it renders exactly as
             before. Chat C owns its conversion. --}}
        @include('partials.capacity-panel', ['center' => $center, 'unaccounted' => $unaccounted ?? 0])

        {{-- PHASE 6 ITEM F. Grid utilities dropped for the same reason as the
             Evacuee Profiling filter bar: this form now sits in the left two
             thirds of row 1, and fixed columns there are narrower still.
             .filter-bar wraps on its own. --}}
        <form method="GET" class="filter-bar" role="search">
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

{{-- ROW 2 -- the households table, full content width. --}}
<div class="mt-4">
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
                            <td data-label="Household Head" data-fit>{{ $h->headMember?->full_name ?? '-' }}</td>
                            <td data-label="Family Size" data-numeric class="whitespace-nowrap">{{ $h->members_present }} / {{ $h->number_of_members }}</td>
                            {{-- PHASE 6 ITEM 2. nowrap. "Aug 02, 2026 - 08:48 AM" has five break
                                 opportunities in it, and in a squeezed column the browser
                                 took every one of them. A timestamp is one value. --}}
                            <td data-label="Check-in" data-numeric class="whitespace-nowrap">{{ $h->checked_in_at?->format('M d, Y - h:i A') ?? '-' }}</td>
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
                                   href="{{ route('barangay.evacuees.index') }}?edit={{ $h->id }}">Edit Family</a>
                                @if($h->status === 'checked_in')
                                    {{-- PHASE 5 ITEM 8b. Deliberately shown even
                                         when a transfer is in progress, unlike
                                         "Transfer" below. The modal reports
                                         WHY it is blocked, which is more useful
                                         than a control that silently is not there
                                         -- and it is the only way a staff member
                                         can find out that the transfer is what is
                                         stopping them. --}}
                                    <button type="button" class="btn-link"
                                            data-presence="{{ $h->id }}">Update Presence</button>
                                    {{-- PHASE 2 ITEM 8, relabelled in PHASE 6 ITEM 4.
                                         It was called "Move to Shelter" because a
                                         Transfer Head control also sat in this
                                         row and two things called Transfer is how
                                         a demo goes wrong. Transfer Head now lives
                                         inside Edit Family, so the collision is
                                         gone and the shorter label wins. --}}
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
                                                data-center-name="{{ $center->name }}">Transfer</button>
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
                <button type="button" class="btn-link" id="ci-edit-family">Edit Family</button>
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
    {{-- PHASE 5 ITEM 8b. Not folded into transfer-modals: presence correction is
         not a transfer action, and the Transfers pages include that partial but
         have no household rows to correct. --}}
    @include('partials.presence-modal')
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
