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
    <input type="search" name="q" value="{{ request('q') }}" placeholder="Search any member name&hellip;" aria-label="Search by any member name">
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

{{-- ======== DROP 2 -- separated households at this shelter ========
     A household declared "separated from their family" at registration. Once
     the family is also at this shelter, the two records can be collapsed into
     one.

     ALWAYS RENDERED, count in the heading, written empty state. Empty is the
     normal answer.

     NOTHING IS MATCHED BY NAME. The operator picks the family and identifies
     which of that family's existing entries are the same people now standing
     here. Both are human statements. The previous design inferred this from
     names and could not be made to work.

     Everything is server-rendered -- no fetch, no dynamically built rows. Each
     fragment gets its own complete form with every family and every family
     member already in the markup, so there is nothing to load and nothing that
     can race. --}}
<div class="card panel table-panel">
    <h2 class="panel-title">Separated Households at This Shelter ({{ $separatedHouseholds->count() }})</h2>
    <p class="text-sm text-ink-muted">
        These households told us at registration that their family evacuated somewhere else.
        When that family is also sheltering here, reunite the records so the family appears once.
        Each household below still counts as its own family at this shelter until you do.
    </p>

    @forelse($separatedHouseholds as $frag)
        @php
            $families = $familyOptions[$frag->id] ?? collect();
        @endphp
        <div class="mt-4 rounded bg-info-bg p-3">
            <p class="text-sm text-info">
                <span class="font-mono">{{ $frag->household_code }}</span>
                &mdash;
                {{ $frag->members->pluck('full_name')->implode(', ') }}
            </p>

            @if($families->isEmpty())
                <p class="empty-note">
                    No other household at this shelter to reunite them with yet. When their
                    family arrives, or is transferred here, they will be selectable.
                </p>
            @else
                <form method="POST" action="{{ route('barangay.evacuees.reunite') }}" class="mt-2">
                    @csrf
                    <input type="hidden" name="fragment_id" value="{{ $frag->id }}">

                    <div class="field">
                        <label for="reunite-family-{{ $frag->id }}">Which family are they part of?</label>
                        <select id="reunite-family-{{ $frag->id }}" name="family_id" required
                                data-reunite-select="{{ $frag->id }}">
                            <option value="">Select the family</option>
                            @foreach($families as $fam)
                                <option value="{{ $fam->id }}">
                                    {{ $fam->household_code }} &mdash; {{ $fam->headMember()?->full_name ?? 'No head listed' }}
                                    ({{ $fam->members->count() }} members)
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <fieldset class="mt-2">
                        <legend class="text-sm font-semibold">
                            Did that family already list any of these people?
                        </legend>
                        <p class="text-sm text-ink-soft">
                            Tick an entry only if it is the SAME PERSON now standing here. Ticked
                            entries are replaced by the newer record taken at registration.
                            Leave everything unticked if the family never listed them.
                        </p>
                        {{-- Every family's members are rendered, then all but the
                             selected family's are hidden. Server-rendered rather
                             than fetched: nothing to load, nothing that can race,
                             and the correct default state is in the markup --
                             delegation cannot observe a row being cloned in. --}}
                        <div class="checkbox-list" data-reunite-list="{{ $frag->id }}">
                            @foreach($families as $fam)
                                <div data-reunite-group="{{ $fam->id }}" hidden>
                                    @foreach($fam->members as $fm)
                                        <label class="flex items-start gap-2">
                                            <input type="checkbox" name="stale[]" value="{{ $fm->id }}" class="mt-1">
                                            <span>
                                                {{ $fm->full_name }}
                                                @if(! $fm->is_present)
                                                    <span class="badge">Not present</span>
                                                @endif
                                            </span>
                                        </label>
                                    @endforeach
                                </div>
                            @endforeach
                            <p class="empty-note" data-reunite-hint="{{ $frag->id }}">
                                Select the family above to see who they already listed.
                            </p>
                        </div>
                    </fieldset>

                    <div class="modal-actions mt-2">
                        <button type="submit" class="btn-primary">Reunite records</button>
                    </div>
                </form>
            @endif
        </div>
    @empty
        <p class="empty-note">
            No separated households at this shelter. This is the normal result &mdash; a household
            appears here only when staff ticked "Separated from their family" while registering it.
        </p>
    @endforelse
</div>

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
                        {{-- DROP 1. Status is never colour-only, so the badge
                             carries its own words. This household counts as its
                             own affected family here -- the badge records that
                             the family is split, not that the count is wrong. --}}
                        @if($h->is_separated)
                            <span class="block"><span class="badge">Separated from family</span></span>
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
                        {{-- PHASE 7 ITEM 1. Was an inline destroy form with a
                             data-confirm dialog that named the code and nothing
                             else. It is a button now: staff.js fills the
                             confirmation modal from these attributes and points
                             the form at the right household. Everything here is
                             a plain escaped echo, so an apostrophe in a name is
                             safe in the attribute. --}}
                        <button type="button" class="btn-link btn-link-danger"
                                data-delete-household="{{ $h->id }}"
                                data-hh-code="{{ $h->household_code }}"
                                data-hh-head="{{ $h->headMember?->full_name }}"
                                data-hh-members="{{ $h->number_of_members }}"
                                data-hh-checked-in="{{ $h->status === 'checked_in' ? '1' : '0' }}">Remove</button>
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

{{-- ======== PHASE 7 ITEM 1 -- Remove Household confirmation ========
     Not a shared partial: it calls route() for the destroy URL template, and a
     shared partial in this codebase is allowed only when it has zero role
     branching AND makes no route() calls. City Admin has no household destroy
     route at all, so there is no second consumer to share it with anyway.

     The `:id` placeholder survives route(): Laravel's route URL generator keeps
     a colon unencoded, which is the same trick EvacueeConfig.showUrlTemplate
     already relies on a few lines below. --}}
<div class="modal-backdrop" id="deleteHouseholdModal"
     data-url-template="{{ route('barangay.evacuees.destroy', ':id') }}" hidden>
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="dhTitle">
        <div class="modal-head">
            <h2 id="dhTitle">Remove Household</h2>
            <button type="button" class="icon-btn" data-close-modal aria-label="Close">&times;</button>
        </div>

        <p>This permanently deletes the family record and every person in it. It cannot be undone.</p>

        <div class="mt-3 flex flex-col gap-2">
            <div class="flex flex-wrap justify-between gap-2">
                <span class="font-semibold">Household code</span>
                <span id="dh-code" data-numeric></span>
            </div>
            <div class="flex flex-wrap justify-between gap-2">
                <span class="font-semibold">Family head</span>
                <span id="dh-head"></span>
            </div>
            <div class="flex flex-wrap justify-between gap-2">
                <span class="font-semibold">People in this record</span>
                <span id="dh-members" data-numeric></span>
            </div>
        </div>

        {{-- destroy() refuses a checked-in household. Saying so before the round
             trip turns a validation error into an instruction. --}}
        <p class="alert alert-warning mt-3" id="dh-blocked" hidden>
            This family is currently checked in. Check them out of the shelter first, then remove the record.
        </p>

        <form method="POST" id="deleteHouseholdForm" action="">
            @csrf
            @method('DELETE')
            <div class="modal-actions flex flex-col gap-2 sm:flex-row sm:justify-end">
                <button type="button" class="btn-secondary" data-close-modal>Cancel</button>
                <button type="submit" class="btn-danger" id="dh-submit">Delete permanently</button>
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
