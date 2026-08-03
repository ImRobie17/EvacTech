@extends('layouts.staff')

@section('title', 'Evacuee Profiling')
@section('page-title', 'Evacuee Profiling')
@section('page-subtitle', 'Register households and manage evacuee records.')

@section('page-actions')
    <button type="button" class="btn-primary w-full sm:w-auto" data-open-modal="evacueeModal" data-mode="create">+ Register Household</button>
@endsection

@section('content')
{{-- PHASE 6 ITEM F. This was `sm:grid sm:grid-cols-2 lg:grid-cols-7`. Seven
     equal columns gave every control one seventh of the row regardless of how
     much text it held, so "All origin barangays" and "All vulnerable
     categories" were clipped mid-word on a 1920px screen -- the overlap that
     was reported.

     The grid utilities are gone. .filter-bar's own rule already stacks on a
     phone and becomes a wrapping flex row from 768px, where each control takes
     the width its longest option needs and the row wraps when it runs out.
     Nothing is clipped at any width, and a filter added later needs no column
     count updating. --}}
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
    {{-- Phase 2 item 6: age group and vulnerable category are now SEPARATE
         controls. They used to share one dropdown because Senior Citizen and
         Infant were classifications; they are derived age tiers now, so mixing
         them would let an operator filter by "Senior" and by "PWD" only one at
         a time. --}}
    <select name="age_group" aria-label="Filter by age group">
        <option value="">All age groups</option>
        @foreach($ageGroups as $key => $label)
            <option value="{{ $key }}" @selected(request('age_group') === $key)>{{ $label }}</option>
        @endforeach
    </select>
    <select name="category" aria-label="Filter by vulnerable category">
        <option value="">All vulnerable categories</option>
        @foreach($classifications as $c)
            <option value="{{ $c->id }}" @selected(request('category') == $c->id)>{{ $c->name }}</option>
        @endforeach
    </select>
    <label class="inline-flex min-h-[44px] items-center gap-2 text-sm">
        <input type="checkbox" name="single_headed" value="1" class="h-5 w-5 shrink-0" @checked(request()->boolean('single_headed'))>
        <span>Single-headed only</span>
    </label>
    <button type="submit" class="btn-secondary">Apply</button>
</form>

<div class="card panel table-panel">
    {{-- data-stack + a data-label on every <td>: one change, never one without
         the other. Seven columns is the widest table in the barangay screens and
         the one that most needed to stop scrolling sideways on a phone. --}}
    <table class="data-table" data-stack>
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
                    <td data-label="Household ID" data-numeric class="whitespace-nowrap">{{ $h->household_code }}</td>
                    <td data-label="Household Head" data-fit>
                        {{ $h->headMember?->full_name ?? '-' }}
                        {{-- Derived, never stored: one person present, currently
                             checked in. Still NOT a vulnerable classification --
                             it is a household-level fact, which is why it is not
                             in the tag column either.

                             PHASE 6. It used to sit in Family Size. That column
                             holds a one- or two-digit number, so a badge reading
                             "Single-headed" set the column's minimum width and
                             made Family Size wider than Household Head -- a
                             count taking more room than a person's name. On its
                             own line under the head it costs no width at all,
                             and it sits beside the household's identity, which
                             is what it actually describes. --}}
                        @if($h->isSingleHeaded())
                            <span class="block"><span class="badge badge-warning">Single-headed</span></span>
                        @endif
                    </td>
                    <td data-label="Family Size" data-numeric class="whitespace-nowrap">{{ $h->number_of_members }}</td>
                    <td data-label="Address" data-fit>{{ $h->origin_address }}</td>
                    <td data-label="Shelter" data-fit>{{ $h->evacuationCenter?->name ?? '-' }}</td>
                    <td data-label="Vulnerable Tags">
                        @php
                            // Retired classifications (Senior Citizen, Infant) are
                            // age tiers now and must not show as vulnerability badges.
                            $tags = $h->members->flatMap->vulnerableClassifications
                                ->where('is_selectable', true)->unique('id');
                        @endphp
                        {{-- Wraps: a household with four tags must not force the
                             stacked card wider than the screen. --}}
                        <span class="flex flex-wrap justify-end gap-1">
                            @forelse($tags as $tag)
                                <span class="badge badge-info">{{ $tag->name }}</span>
                            @empty
                                <span class="text-ink-muted">None</span>
                            @endforelse
                        </span>
                    </td>
                    <td class="actions-cell" data-label="Actions">
                        {{-- PHASE 6 ITEM 10. Read before edit, deliberately first
                             in the row: opening a live form to check a birthdate
                             is one mis-keyed field away from changing a record
                             nobody meant to touch. --}}
                        <button type="button" class="btn-link" data-view-household="{{ $h->id }}">View</button>
                        <button type="button" class="btn-link" data-open-modal="evacueeModal" data-mode="edit" data-household="{{ $h->id }}">Edit Family</button>
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
{{-- PHASE 6 ITEM 11. The Add / Edit Evacuee modal and its member-row template
     moved to partials/evacuee-modal so the Shelter page can open the SAME modal
     in place instead of linking here with ?edit={id}. Markup unchanged; only its
     address did. --}}
@include('partials.evacuee-modal', [
    'storeUrl' => route('barangay.evacuees.store'),
    'barangays' => $barangays,
    'classifications' => $classifications,
    'ageGroups' => $ageGroups,
    'defaultBarangayId' => $defaultBarangayId ?? null,
])

{{-- PHASE 6 ITEM 10. Read-only view. No route() calls inside it -- staff.js
     renders it from window.EvacueeConfig.showUrlTemplate, set below. --}}
@include('partials.household-view-modal')

{{-- ======== Select New Family Head modal (shared markup/IDs with Shelter page's JS) ======== --}}
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
