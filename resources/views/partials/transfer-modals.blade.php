{{--
    PHASE 2 ITEM 8 -- the four transfer modals.

    Included by the two Transfers pages and by the two shelter pages, always
    inside a @push('modals') block. Like partials/transfer-table, this contains
    NO role branching and never calls route(): the including page passes a $tx
    array of URL templates, and transfers.js reads them from window.TransferConfig.

    Confirm and Record OUT have no modal. They are single-click POST forms in
    the table with data-confirm, which staff.js already wires up.

    The markup reuses the existing .modal-backdrop / .modal / .field /
    .checkbox-list classes so that the generic open-close handling in staff.js
    and the light/dark palettes both apply unchanged. Layout inside is Tailwind
    utilities. No new CSS classes anywhere.
--}}

{{-- ======== New transfer (household picker + destination) ======== --}}
<div class="modal-backdrop" id="transferCreateModal" hidden>
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="txCreateTitle">
        <div class="modal-head">
            <h2 id="txCreateTitle">Move Family to Another Shelter</h2>
            <button type="button" class="icon-btn" data-close-modal aria-label="Close">&times;</button>
        </div>

        {{-- Picker. Hidden when the modal is opened from a household row, where
             the family is already known. --}}
        <div class="field search-inline" id="txPicker" hidden>
            <label for="tx-search">Household code or head name</label>
            <div class="flex flex-col gap-2 sm:flex-row">
                <input type="search" id="tx-search" class="sm:flex-1" placeholder="e.g. Dela Cruz, Juan" autocomplete="off">
                <button type="button" class="btn-secondary" id="tx-search-btn">Search</button>
            </div>
            <ul class="search-results" id="tx-results" hidden></ul>
        </div>

        <form method="POST" id="transferCreateForm" hidden>
            @csrf
            <input type="hidden" name="household_id" id="tx-household-id">

            <div class="mb-3">
                <p><strong id="tx-code"></strong> <span id="tx-head"></span></p>
                <p class="text-sm text-ink-muted" id="tx-origin"></p>
            </div>

            <div class="field">
                <label for="tx-destination">Destination shelter</label>
                <select name="to_center_id" id="tx-destination" required>
                    <option value="">Select a shelter</option>
                </select>
                {{-- An overcapacity shelter stays selectable: during a real
                     evacuation there may be nowhere else to send people. The
                     option text says so, and this note explains the choice. --}}
                <p class="mt-1 text-sm text-ink-muted" id="tx-destination-note">Shelters over capacity can still be chosen, and are labelled.</p>
            </div>

            <div class="field">
                <label for="tx-reason">Reason (optional)</label>
                <input type="text" name="reason" id="tx-reason" maxlength="255" autocomplete="off"
                       placeholder="e.g. Shelter at capacity, family requested move">
            </div>

            <p class="text-sm text-ink-muted">
                The family stays counted at their current shelter until the destination records their arrival.
                Nobody disappears from a headcount while they are travelling.
            </p>

            <div class="modal-actions flex flex-col gap-2 sm:flex-row sm:justify-end">
                <button type="button" class="btn-secondary" data-close-modal>Cancel</button>
                <button type="submit" class="btn-primary">Request Transfer</button>
            </div>
        </form>
    </div>
</div>

{{-- ======== Receive: tick who actually arrived ======== --}}
<div class="modal-backdrop" id="transferReceiveModal" hidden>
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="txReceiveTitle">
        <div class="modal-head">
            <h2 id="txReceiveTitle">Record Arrival</h2>
            <button type="button" class="icon-btn" data-close-modal aria-label="Close">&times;</button>
        </div>

        <form method="POST" id="transferReceiveForm">
            @csrf
            <p><strong id="tx-rc-code"></strong> <span id="tx-rc-head"></span></p>
            <p class="text-sm text-ink-muted" id="tx-rc-expected"></p>

            <p class="mt-3">Tick everyone who actually arrived at this shelter:</p>
            <div id="tx-rc-members" class="checkbox-list"></div>

            <p class="text-sm text-ink-muted">
                Anyone left unticked needs a reason. Choosing "Unknown" is what raises a flag for
                follow-up, so the other three answers keep the alert meaningful. Nobody is ever
                labelled missing: the system only records that a headcount did not reconcile.
            </p>

            <div class="modal-actions flex flex-col gap-2 sm:flex-row sm:justify-end">
                <button type="button" class="btn-secondary" data-close-modal>Cancel</button>
                <button type="submit" class="btn-primary">Confirm Arrival</button>
            </div>
        </form>
    </div>
</div>

{{-- ======== Refuse (pending only) ======== --}}
<div class="modal-backdrop" id="transferRefuseModal" hidden>
    <div class="modal modal-narrow" role="dialog" aria-modal="true" aria-labelledby="txRefuseTitle">
        <div class="modal-head">
            <h2 id="txRefuseTitle">Refuse Transfer</h2>
            <button type="button" class="icon-btn" data-close-modal aria-label="Close">&times;</button>
        </div>

        <p>Refusing tells the origin shelter not to send <strong id="tx-rf-label"></strong>, and alerts City Admin.</p>
        <p class="text-sm text-ink-muted">
            A transfer can only be refused before it is confirmed. Once you confirm, you are committed:
            receive the family, then file a fresh transfer if they need to move on.
        </p>

        <form method="POST" id="transferRefuseForm">
            @csrf
            <div class="field">
                <label for="tx-rf-reason">Reason</label>
                <input type="text" name="refusal_reason" id="tx-rf-reason" maxlength="255" required autocomplete="off"
                       placeholder="e.g. We are over capacity">
            </div>
            <div class="modal-actions flex flex-col gap-2 sm:flex-row sm:justify-end">
                <button type="button" class="btn-secondary" data-close-modal>Cancel</button>
                <button type="submit" class="btn-danger">Refuse Transfer</button>
            </div>
        </form>
    </div>
</div>

{{-- ======== Cancel ======== --}}
<div class="modal-backdrop" id="transferCancelModal" hidden>
    <div class="modal modal-narrow" role="dialog" aria-modal="true" aria-labelledby="txCancelTitle">
        <div class="modal-head">
            <h2 id="txCancelTitle">Cancel Transfer</h2>
            <button type="button" class="icon-btn" data-close-modal aria-label="Close">&times;</button>
        </div>

        <p>Cancel the transfer of <strong id="tx-cl-label"></strong>?</p>
        <p class="text-sm text-ink-muted">
            The household stays checked in at the origin shelter with its headcount unchanged,
            because it never moved in the records.
        </p>
        {{-- Shown by transfers.js only for an in-transit cancellation, which is
             City Admin only and needs a reason. --}}
        <p class="text-sm text-ink-muted" id="tx-cl-transit-note" hidden>
            This family has already departed. Record what happened to them.
        </p>

        <form method="POST" id="transferCancelForm">
            @csrf
            <div class="field">
                <label for="tx-cl-reason">Reason <span id="tx-cl-optional">(optional)</span></label>
                <input type="text" name="cancellation_reason" id="tx-cl-reason" maxlength="255" autocomplete="off"
                       placeholder="e.g. Family returned home instead">
            </div>
            <div class="modal-actions flex flex-col gap-2 sm:flex-row sm:justify-end">
                <button type="button" class="btn-secondary" data-close-modal>Keep Transfer</button>
                <button type="submit" class="btn-danger">Cancel Transfer</button>
            </div>
        </form>
    </div>
</div>

{{-- ======== Resolve an absence (PHASE 5 ITEM 8b) ======== --}}
{{--
    Records what happened to someone who did not arrive. Only "Arrived" changes
    a headcount -- it routes through PresenceService. The other two change NO
    counts at all: the person was already absent from members_present and from
    the shelter's occupancy, and the only thing that changes is that the system
    stops asking.

    "Travelled separately" is deliberately not offered. That is a reason, not a
    resolution, and it never raised anything to resolve.

    The wording never says "missing". In Philippine DRRM reporting that is a
    formal category that travels upward beside dead and injured.
--}}
<div class="modal-backdrop" id="transferResolveModal" hidden>
    <div class="modal modal-narrow" role="dialog" aria-modal="true" aria-labelledby="txResolveTitle">
        <div class="modal-head">
            <h2 id="txResolveTitle">Record What Happened</h2>
            <button type="button" class="icon-btn" data-close-modal aria-label="Close">&times;</button>
        </div>

        <p><strong id="tx-rs-name"></strong> did not arrive at the destination shelter.</p>

        <form method="POST" id="transferResolveForm">
            @csrf
            <input type="hidden" name="member_id" id="tx-rs-member-id">

            <div class="field">
                <label for="tx-rs-resolution">What happened?</label>
                <select name="resolution" id="tx-rs-resolution" required>
                    <option value="">Choose one</option>
                    <option value="{{ \App\Models\ShelterTransfer::RESOLUTION_ARRIVED }}">They arrived at the shelter</option>
                    @foreach (\App\Models\ShelterTransfer::RECORDED_RESOLUTIONS as $rsCode => $rsLabel)
                        <option value="{{ $rsCode }}">{{ $rsLabel }}</option>
                    @endforeach
                </select>
            </div>

            <p class="text-sm text-ink-muted" id="tx-rs-note">
                Only "They arrived at the shelter" changes the headcount. The others are recorded as
                a fact and leave every count unchanged.
            </p>

            <div class="modal-actions flex flex-col gap-2 sm:flex-row sm:justify-end">
                <button type="button" class="btn-secondary" data-close-modal>Cancel</button>
                <button type="submit" class="btn-primary">Save</button>
            </div>
        </form>
    </div>
</div>
