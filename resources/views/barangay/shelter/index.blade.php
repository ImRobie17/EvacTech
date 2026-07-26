@extends('layouts.staff')

@section('title', 'Evacuation Shelter')
@section('page-title', 'Evacuation Shelter')
@section('page-subtitle', $center?->name ?? 'No assigned shelter')

@section('page-actions')
    <button type="button" class="btn-primary" data-open-modal="checkinModal">&check; Check-in Existing Family</button>
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
<section class="dash-columns">
    <div>
        @include('partials.capacity-panel', ['center' => $center])

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

        <div class="card panel table-panel">
            <table class="data-table">
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
                            <td>{{ $h->headMember?->full_name ?? '-' }}</td>
                            <td data-numeric>{{ $h->members_present }} / {{ $h->number_of_members }}</td>
                            <td data-numeric>{{ $h->checked_in_at?->format('M d, Y - h:i A') ?? '-' }}</td>
                            <td>
                                <span class="badge {{ $h->status === 'checked_in' ? 'badge-success' : ($h->status === 'checked_out' ? 'badge-warning' : 'badge-info') }}">
                                    {{ ucfirst(str_replace('_', ' ', $h->status)) }}
                                </span>
                            </td>
                            <td class="actions-cell">
                                <button type="button" class="btn-link" data-open-modal="evacueeEditRedirect" data-household="{{ $h->id }}"
                                        onclick="window.location='{{ route('barangay.evacuees.index') }}?edit={{ $h->id }}'">Edit Family Group</button>
                                @if($h->status === 'checked_in')
                                    <form method="POST" action="{{ route('barangay.shelter.checkout', $h) }}" class="inline-form"
                                          data-confirm="Check out {{ $h->headMember?->full_name }}'s household?">
                                        @csrf
                                        <button type="submit" class="btn-link btn-link-danger">Check-out</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="empty-note">No households at this shelter match your search.</td></tr>
                    @endforelse
                </tbody>
            </table>
            @if($households instanceof \Illuminate\Pagination\AbstractPaginator)
                {{ $households->links() }}
            @endif
        </div>
    </div>

    <div class="dash-side">
        <h2 class="panel-title">Recent Activity</h2>
        <div class="card panel activity-panel">
            @forelse($recent as $event)
                <div class="activity-row">
                    <span class="badge {{ $event['type'] === 'check_in' ? 'badge-success' : 'badge-warning' }}">
                        {{ $event['type'] === 'check_in' ? 'Check-in' : 'Check-out' }}
                    </span>
                    <span class="activity-name">{{ $event['household']->headMember?->full_name ?? $event['household']->household_code }}</span>
                    <time class="activity-time" datetime="{{ $event['at']->toIso8601String() }}">{{ $event['at']->diffForHumans() }}</time>
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
            <div class="search-inline-row">
                <input type="search" id="ci-search" placeholder="e.g. Dela Cruz, Juan" autocomplete="off">
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
            <div class="modal-actions">
                <button type="button" class="btn-link" id="ci-edit-family">Edit Family Group</button>
                <span class="spacer"></span>
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
