{{-- =========================================================================
     Shared Add / Edit Evacuee modal + member-row template.

     PHASE 6 ITEM 11. This lived inline in barangay/evacuees/index. The barangay
     Shelter page could not use it, so its "Edit Family" action was an anchor to
     `evacuees.index?edit={id}` -- it navigated away, and after saving you were
     left on Evacuee Profiling instead of the shelter you came from. Both pages
     now include this file and open the same modal IN PLACE.

     NO route() CALLS IN HERE, and no role branching. Both including pages are
     barangay screens today, so route() would work -- but the shared-partial rule
     exists precisely so that stops being true safely. The including page passes
     $storeUrl in; every other URL the modal needs already arrives through
     window.EvacueeConfig, which staff.js reads.

     REQUIRED from the including page:
       $storeUrl           -- form action for a new household
       $barangays          -- Barangay collection for the origin select
       $classifications    -- SELECTABLE VulnerableClassification collection
       $ageGroups          -- AgeTier::options()
       $defaultBarangayId  -- pre-selects the active shelter's barangay (nullable)

     The including page must ALSO set window.EvacueeConfig, or initEvacueeForm()
     returns early and this markup sits inert. staff.js prints a reason.

     Element ids are load-bearing: staff.js finds evacueeModal, evacueeForm,
     evacueeFormMethod, evacueeModalTitle, evacueeNote, headRow, memberRows,
     addMemberBtn, transferHeadBtn, ev-barangay, ev-address and
     memberRowTemplate by id. Two copies on one page would break all of them, so
     include this ONCE per page.
     ========================================================================= --}}
{{-- ======== Add / Edit Evacuee modal ======== --}}
<div class="modal-backdrop" id="evacueeModal" hidden>
    <div class="modal modal-wide" role="dialog" aria-modal="true" aria-labelledby="evacueeModalTitle">
        <div class="modal-head">
            <h2 id="evacueeModalTitle">Add New Evacuee Profile</h2>
            <button type="button" class="icon-btn" data-close-modal aria-label="Close">&times;</button>
        </div>

        {{-- PHASE 6. Confirming a head transfer no longer navigates, so there is
             no page reload to carry a flash message. This is where that
             confirmation lands instead. Hidden until staff.js fills it, cleared
             by resetForm() so it can never appear over a different family. --}}
        <p class="alert alert-success" id="evacueeNote" role="status" hidden></p>

        <form method="POST" id="evacueeForm" action="{{ $storeUrl }}">
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
            <div class="field"><label>Date of birth <small>(optional)</small></label><input type="date" data-field="birthdate"
                       min="{{ \App\Support\MemberRules::minBirthdate() }}"
                       max="{{ \App\Support\MemberRules::maxBirthdate() }}"></div>
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
