@extends('layouts.cityadmin')

@section('title', 'Evacuee Profiling')
@section('page-title', 'Evacuee Profiling')
@section('page-subtitle', 'All registered households across every shelter.')
@section('page-actions')
    <button type="button" class="btn-primary" data-open-modal="cityEvacueeModal">+ Register Household</button>
@endsection

@section('content')
<form method="GET" class="filter-bar" role="search">
    {{-- The raw ellipsis and em dashes on this page were non-ASCII glyphs, the
         class of character that has been double-encoded into mojibake in this
         codebase before. Entities in raw HTML; plain ASCII inside {{ }}, because
         Blade's e() double-encodes an entity written there into literal text. --}}
    <input type="search" name="q" value="{{ request('q') }}" placeholder="Search any member name" aria-label="Search by any member name">
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
    {{-- Phase 2 item 6: age group and vulnerable category are separate controls.
         Single-headed is a derived household fact, not a tag, so it is its own
         checkbox rather than an entry in the category list. --}}
    <select name="age_group" aria-label="Filter age group">
        <option value="">All age groups</option>
        @foreach($ageGroups as $key => $label)<option value="{{ $key }}" @selected(request('age_group') === $key)>{{ $label }}</option>@endforeach
    </select>
    <select name="category" aria-label="Filter vulnerable category">
        <option value="">All vulnerable categories</option>
        @foreach($classifications as $c)<option value="{{ $c->id }}" @selected(request('category') == $c->id)>{{ $c->name }}</option>@endforeach
    </select>
    <label class="inline-flex min-h-[44px] items-center gap-2 text-sm">
        <input type="checkbox" name="single_headed" value="1" class="h-5 w-5 shrink-0" @checked(request()->boolean('single_headed'))>
        <span>Single-headed only</span>
    </label>
    <button type="submit" class="btn-secondary">Apply</button>
</form>

<div class="card panel table-panel">
    <table class="data-table" data-stack>
        <thead>
            <tr><th scope="col">Household ID</th><th scope="col">Head</th><th scope="col">Size</th><th scope="col">Barangay</th><th scope="col">Shelter</th><th scope="col">Tags</th><th scope="col">Status</th><th scope="col">Actions</th></tr>
        </thead>
        <tbody>
            @forelse($households as $h)
                <tr>
                    <td data-label="Household ID" data-numeric class="whitespace-nowrap">{{ $h->household_code }}</td>
                    {{-- PHASE 6. Single-headed moved off Size and onto its own line
                         under the head's name. Size carries a one- or two-digit
                         number; a badge in it set the column's minimum width and
                         left Size wider than Head. It is a household-level fact
                         rather than a vulnerable classification, so it does not
                         belong in the Tags column either. --}}
                    <td data-label="Head" data-fit>
                        {{ $h->headMember?->full_name ?? '-' }}
                        @if($h->isSingleHeaded())
                            <span class="block"><span class="badge badge-warning">Single-headed</span></span>
                        @endif
                    </td>
                    <td data-label="Size" data-numeric class="whitespace-nowrap">{{ $h->number_of_members }}</td>
                    <td data-label="Barangay" data-fit>{{ $h->originBarangay?->name }}</td>
                    <td data-label="Shelter" data-fit>{{ $h->evacuationCenter?->name ?? '-' }}</td>
                    {{-- Tags wrap onto their own lines in a stacked card rather
                         than forcing the row wide. flex-wrap with a gap keeps
                         them legible at 380px. --}}
                    <td data-label="Tags">
                        @php
                            // Retired classifications (Senior Citizen, Infant) are
                            // age tiers now and must not render as vulnerability badges.
                            $tags = $h->members->flatMap->vulnerableClassifications
                                ->where('is_selectable', true)->unique('id');
                        @endphp
                        <span class="flex flex-wrap justify-end gap-1 md:justify-start">
                            @forelse($tags as $tag)<span class="badge badge-info">{{ $tag->name }}</span>@empty<span class="text-muted">None</span>@endforelse
                        </span>
                    </td>
                    <td data-label="Status"><span class="badge {{ $h->status === 'checked_in' ? 'badge-success' : 'badge-warning' }}">{{ ucfirst(str_replace('_', ' ', $h->status)) }}</span></td>
                    {{-- PHASE 6 ITEM 10. City Admin reads household details too.
                         This screen has no edit form, so View is the whole
                         actions column -- and the modal it opens has no route
                         back into an editor. --}}
                    <td class="actions-cell" data-label="Actions">
                        <button type="button" class="btn-link" data-view-household="{{ $h->id }}">View</button>
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="empty-note">No households found.</td></tr>
            @endforelse
        </tbody>
    </table>
    {{ $households->links() }}
</div>
@endsection

@push('modals')
{{-- PHASE 6 ITEM 10. Same partial the barangay screens include. It contains no
     route() call and no role branching -- the URL arrives through
     window.HouseholdViewConfig below, which is why this works unchanged across
     both roles. --}}
@include('partials.household-view-modal')

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
                        <option value="">Select&hellip;</option>
                        @foreach($barangays as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach
                    </select>
                </div>
                {{-- PHASE 9 ITEM 4 -- pre-registration.

                     `required` is gone. It made the field mandatory for BOTH
                     buttons, including Save, whose whole purpose is to record a
                     family before anyone knows where they will be sheltered --
                     and the controller then discarded the choice anyway. The
                     server now requires it only when checkin=1, which is what
                     "Save &amp; Check-in" posts. --}}
                <div class="field">
                    <label for="ce-shelter">Evacuation shelter <small>(only needed to check in now)</small></label>
                    <select id="ce-shelter" name="evacuation_center_id">
                        <option value="">Not assigned yet</option>
                        @foreach($shelters as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach
                    </select>
                </div>
            </div>
            <div class="field"><label for="ce-address">Family address</label><input type="text" id="ce-address" name="address" required maxlength="255"></div>
                <div class="field">
                    {{-- DROP 1. A DECLARED fact, not a detected one. The person
                         at the desk says their family is sheltering elsewhere and
                         the operator records it. Nothing is matched by name --
                         that approach missed "Maria Santos" against "Maria Santos
                         Jr." and would have proposed merging strangers if it were
                         loosened.

                         Nothing happens to counts. This household is its own
                         affected family at this shelter. The flag is what lets
                         the arrival screen offer to reunite them later. --}}
                    <label for="ce-separated" class="flex items-start gap-2">
                        <input type="checkbox" id="ce-separated" name="is_separated" value="1"
                               class="mt-1">
                        <span>Separated from their family (family is sheltering elsewhere)</span>
                    </label>
                    <small class="field-hint">Tick when this household is part of a
                        larger family that evacuated to a different shelter. They stay
                        registered here; staff can reunite the records later.</small>
                </div>

            <fieldset class="member-fieldset" id="ceHeadFieldset"><legend>Household Head</legend><div id="ceHeadRow"></div></fieldset>
            <fieldset class="member-fieldset"><legend>Family Members</legend><div id="ceMemberRows"></div>
                <button type="button" class="btn-secondary" id="ceAddMemberBtn">+ Add family member</button>
            </fieldset>

            {{-- Three buttons stack on a phone and sit in a row from 640px, so
                 "Save" and "Save &amp; Check-in" are never squeezed to two
                 illegible words each at 380px. --}}
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
            {{-- Birthdate is OPTIONAL as of Phase 2: tag the family fast now,
                 fill the birthday in later. --}}
            <div class="field"><label>Date of birth <small>(optional)</small></label><input type="date" data-field="birthdate"
                       min="{{ \App\Support\MemberRules::minBirthdate() }}"
                       max="{{ \App\Support\MemberRules::maxBirthdate() }}"></div>
            <div class="field"><label>Sex</label><select data-field="sex" required><option value="">Select&hellip;</option><option value="male">Male</option><option value="female">Female</option></select></div>
        </div>

        <div class="mt-3 grid grid-cols-1 gap-4 md:grid-cols-[minmax(0,17rem)_minmax(0,1fr)]">
            {{-- Locked by JS whenever a date of birth is present: the server
                 derives the tier from the birthday and ignores this value. --}}
            <div class="field">
                <label>Age group</label>
                <select data-field="age_group" required>
                    <option value="">Select&hellip;</option>
                    @foreach($ageGroups as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                </select>
                <small class="field-hint">Set automatically from the date of birth. Choose it here when the birthday is not known yet.</small>
            </div>

            <fieldset class="min-w-0 border-0 p-0">
                <legend class="mb-1 text-sm font-semibold">Vulnerable categories <small class="font-normal">(optional, choose any)</small></legend>
                <div class="flex flex-wrap gap-x-5 gap-y-1">
                    @foreach($classifications as $c)
                        {{-- PHASE 7 ITEM 2. Pregnant Woman and Lactating Mother start
                             HIDDEN and are revealed by sex-fields.js only when the sex
                             select on this row reads Female. The default belongs in the
                             markup because a fresh row has no sex chosen and hidden is
                             already the right answer for that -- which means no JS has to
                             observe rows being cloned into the page. --}}
                        <label class="inline-flex min-h-[44px] cursor-pointer items-center gap-2 text-sm"
                               @if($c->isFemaleOnly()) data-female-only hidden @endif>
                            <input type="checkbox" data-field="tags" value="{{ $c->id }}"
                                   data-code="{{ $c->code }}" class="h-5 w-5 shrink-0">
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
    window.CityEvacueeConfig = { autoOpen: false };

    /* PHASE 6 ITEM 10. Its OWN config, not window.EvacueeConfig: that object is
       what initEvacueeForm() in staff.js keys on, and setting it here would send
       that function hunting for an #evacueeForm this screen does not have. The
       viewer reads HouseholdViewConfig first and falls back to EvacueeConfig,
       so the barangay pages needed no change. */
    window.HouseholdViewConfig = {
        showUrlTemplate: "{{ route('city.evacuees.show', ':id') }}",
    };
</script>
@endpush
