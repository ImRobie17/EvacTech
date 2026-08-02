{{--
    PHASE 5 ITEM 8b -- the Update Presence modal, shared by both shelter screens.

    SHARING THIS IS SAFE for the same reason partials/transfer-table and
    partials/transfer-modals are safe, and for a reason the Phase 1 rewrite
    established: this file contains ZERO role branching and never calls route().
    The two shelter pages each build an array of fully-built URL templates and
    hand it over; nothing in here asks "which role am I rendering for?".

    The URLs arrive on window.TransferConfig as .presence and .presenceSave,
    added to the SAME config object the transfer modals already use. That is
    deliberate: one config object per page means one raw echo, and therefore one
    place where the escaped-echo bug could ever reappear, instead of two.

    WIRED BY transfers.js, not staff.js and not cityadmin-shelter.js.
    transfers.js is imported by app.js, so it is already present on every staff
    page including both shelter screens, and it delegates on document. Wiring it
    anywhere else would mean calling openModal() or cdOpen() across an ES module
    boundary by bare name, which throws ReferenceError -- the bug that hid three
    features for a whole phase.

    Markup reuses the existing .modal-backdrop / .modal / .field /
    .checkbox-list classes so the light and dark palettes apply unchanged.
    Layout inside is Tailwind utilities. No new CSS classes.
--}}

<div class="modal-backdrop" id="presenceModal" hidden>
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="presenceTitle">
        <div class="modal-head">
            <h2 id="presenceTitle">Update Presence</h2>
            <button type="button" class="icon-btn" data-close-modal aria-label="Close">&times;</button>
        </div>

        <p><strong id="pr-code"></strong> <span id="pr-head"></span></p>
        <p class="text-sm text-ink-muted" id="pr-summary"></p>

        {{-- Shown INSTEAD of the form when the household cannot be corrected
             right now -- almost always because a shelter transfer is in
             progress. The server refuses the same cases on submit; this exists
             so staff are told why before they fill anything in. --}}
        <div class="alert alert-warning" id="pr-blocked" role="status" hidden>
            <span id="pr-blocked-text"></span>
        </div>

        <form method="POST" id="presenceForm" hidden>
            @csrf

            <p class="mt-3">Tick everyone who is physically at this shelter right now:</p>
            <div id="pr-members" class="checkbox-list"></div>

            {{-- Zero present is a check-out, not a presence correction. The
                 server rejects it either way; this warning stops the round trip
                 and names the control that actually does the job. --}}
            <div class="alert alert-warning" id="pr-none-warning" role="status" hidden>
                At least one person must stay ticked. To record that the whole family has left,
                close this and use Check-out instead.
            </div>

            <p class="text-sm text-ink-muted">
                The shelter headcount is recalculated from these ticks. Anyone left unticked is not
                counted in this shelter's occupancy, and stays on their family's record.
            </p>

            <div class="modal-actions flex flex-col gap-2 sm:flex-row sm:justify-end">
                <button type="button" class="btn-secondary" data-close-modal>Cancel</button>
                <button type="submit" class="btn-primary" id="pr-save">Save Presence</button>
            </div>
        </form>
    </div>
</div>
