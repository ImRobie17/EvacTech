@extends('layouts.staff')

@section('title', 'Evacuee Profiling')
@section('page-title', 'Evacuee Profiling')
@section('page-subtitle', 'Register households and manage evacuee records.')

@section('page-actions')
    <button type="button" class="btn-primary w-full sm:w-auto" data-open-modal="evacueeModal" data-mode="create">+ Register Household</button>
@endsection

@section('content')
{{-- Four filters. Stacked on a phone, two up from 640px, four across from
     1024px -- four side by side at 380px would make every control unusable. --}}
<form method="GET" class="filter-bar sm:grid sm:grid-cols-2 sm:items-end lg:grid-cols-7" role="search">
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
    <button type="submit" class="btn-secondary">Filter</button>
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
                    <td data-label="Household ID" data-numeric>{{ $h->household_code }}</td>
                    <td data-label="Household Head">{{ $h->headMember?->full_name ?? '-' }}</td>
                    <td data-label="Family Size" data-numeric>
                        {{ $h->number_of_members }}
                        {{-- Derived, never stored: one person present, currently
                             checked in. Not a vulnerable classification -- it is a
                             household-level fact, so it lives here rather than in
                             the per-member tag pivot. --}}
                        @if($h->isSingleHeaded())
                            <span class="badge badge-warning">Single-headed</span>
                        @endif
                    </td>
                    <td data-label="Address">{{ $h->origin_address }}</td>
                    <td data-label="Shelter">{{ $h->evacuationCenter?->name ?? '-' }}</td>
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
                        <button type="button" class="btn-link" data-open-modal="evacueeModal" data-mode="edit" data-household="{{ $h->id }}">Edit Family Group</button>
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
{{-- ======== Add / Edit Evacuee modal ======== --}}
<div class="modal-backdrop" id="evacueeModal" hidden>
    <div class="modal modal-wide" role="dialog" aria-modal="true" aria-labelledby="evacueeModalTitle">
        <div class="modal-head">
            <h2 id="evacueeModalTitle">Add New Evacuee Profile</h2>
            <button type="button" class="icon-btn" data-close-modal aria-label="Close">&times;</button>
        </div>

        <form method="POST" id="evacueeForm" action="{{ route('barangay.evacuees.store') }}">
            @csrf
            <input type="hidden" name="_method" value="POST" id="evacueeFormMethod">

            {{-- Origin barangay is now EXPLICIT. It used to be inferred from
                 auth()->user()->barangay_id, but staff are assigned to shelters
                 rather than barangays, and one shelter routinely holds families
                 from several barangays. Defaults to the barangay of the active shelter,
                 which covers the common case in one click.

                 NOT .member-grid: that class goes to FIVE columns at 1280px
                 because it is tuned for the five-field member row below. Two
                 fields in a five-track grid gave a squeezed pair and three empty
                 columns. Two fields get a two-column grid. --}}
            <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                <div class="field">
                    <label for="ev-barangay">Origin barangay</label>
                    <select id="ev-barangay" name="origin_barangay_id" required>
                        <option value="">Select barangay</option>
                        @foreach($barangays as $b)
                            <option value="{{ $b->id }}" @selected(($defaultBarangayId ?? null) == $b->id)>{{ $b->name }}</option>
                        @endforeach
                    </select>
                    <small class="field-hint">Where the family came from, not where they are sheltering.</small>
                </div>
                <div class="field">
                    <label for="ev-address">Family address (house no., street, purok)</label>
                    <input type="text" id="ev-address" name="address" required maxlength="255">
                </div>
            </div>

            <fieldset class="member-fieldset" id="headFieldset">
                <legend>Household Head</legend>
                <div id="headRow"></div>
            </fieldset>

            <fieldset class="member-fieldset">
                <legend>Family Members</legend>
                <div id="memberRows"></div>
                <button type="button" class="btn-secondary w-full sm:w-auto" id="addMemberBtn">+ Add family member</button>
            </fieldset>

            <div class="modal-actions flex flex-col gap-2 sm:flex-row sm:items-center">
                <button type="button" class="btn-link" id="transferHeadBtn" hidden>Transfer Head&hellip;</button>
                <span class="hidden sm:block sm:flex-1"></span>
                <button type="button" class="btn-secondary" data-close-modal>Cancel</button>
                <button type="submit" class="btn-secondary" name="checkin" value="0">Save</button>
                <button type="submit" class="btn-primary" name="checkin" value="1" id="saveCheckinBtn">Save &amp; Check-in</button>
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

{{-- Row template used by JS for both head and members.
     Keeps .member-grid: five fields is exactly what that class is tuned for
     (1 column, 2 at 768px, 5 at 1280px), and it is shared with the City Admin
     views that Chat C has not converted. --}}
<template id="memberRowTemplate">
    <div class="member-row" data-row>
        <input type="hidden" data-field="id" name="">
        {{-- .member-grid stays tuned for exactly these five fields. The two new
             Phase 2 controls go in their own Tailwind grid below rather than
             being crammed in as a sixth and seventh column. --}}
        <div class="member-grid">
            <div class="field"><label>Last name</label><input type="text" data-field="last_name" required maxlength="100"></div>
            <div class="field"><label>First name</label><input type="text" data-field="first_name" required maxlength="100"></div>
            <div class="field"><label>Middle name <small>(optional)</small></label><input type="text" data-field="middle_name" maxlength="100"></div>
            {{-- Birthdate is OPTIONAL as of Phase 2 so staff can tag a family
                 fast during a surge and complete the record later. --}}
            <div class="field"><label>Date of birth <small>(optional)</small></label><input type="date" data-field="birthdate" max="{{ now()->toDateString() }}"></div>
            <div class="field"><label>Sex</label>
                <select data-field="sex" required>
                    <option value="">Select&hellip;</option>
                    <option value="male">Male</option>
                    <option value="female">Female</option>
                </select>
            </div>
        </div>

        <div class="mt-3 grid grid-cols-1 gap-4 md:grid-cols-[minmax(0,17rem)_minmax(0,1fr)]">
            {{-- Age group. Filled in and locked by JS whenever a date of birth is
                 present -- the server ignores this value in that case, so letting
                 it contradict the birthday would only mislead. Required when the
                 birthday is blank, which keeps "Unknown" off a form a City Social
                 Welfare officer signs. --}}
            <div class="field">
                <label>Age group</label>
                <select data-field="age_group" required>
                    <option value="">Select&hellip;</option>
                    @foreach($ageGroups as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
                <small class="field-hint">Set automatically from the date of birth. Choose it here when the birthday is not known yet.</small>
            </div>

            {{-- Categories are checkboxes now. The old control was a
                 &lt;select multiple size="1"&gt; -- a one-row-tall multi-select,
                 effectively unusable on a phone and far under the 44px tap
                 target the design system requires. A person can hold several at
                 once (a pregnant solo parent on 4Ps is three boxes). --}}
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
