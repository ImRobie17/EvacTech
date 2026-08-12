{{-- =========================================================================
     Read-only household view modal.   PHASE 6 ITEM 10.

     Expand a family to READ its dates, tags, presence and status without
     entering edit mode. Previously the only way to see a member's birthdate or
     categories was to open Edit Family, which puts a live form in front of
     someone who only wanted to look -- and one mis-keyed field away from
     changing a record they never meant to touch.

     A modal, not a page, and not an inline expanding row: the same control on
     both barangay tables behaves identically, and nothing reflows the table
     underneath.

     NO route() CALLS and no role branching. Everything is rendered client side
     by initHouseholdView() in staff.js from the existing
     `barangay.evacuees.show` payload, which the including page already supplies
     as window.EvacueeConfig.showUrlTemplate. There is nothing for this partial
     to build a URL for.

     Element ids are load-bearing -- staff.js finds each by id. Include ONCE per
     page.

     WHY IT REUSES showUrlTemplate RATHER THAN A NEW ENDPOINT
     -------------------------------------------------------
     show() already returns members with birthdates, derived age tiers, presence
     and selectable tags, and it already runs authorizeHousehold(). A second
     read endpoint would be a second place for that authorisation check to be
     forgotten. Phase 6 added only the two timestamps and the two counts to it.
     ========================================================================= --}}
<div class="modal-backdrop" id="householdViewModal" hidden>
    <div class="modal modal-wide" role="dialog" aria-modal="true" aria-labelledby="hvTitle">
        <div class="modal-head">
            <h2 id="hvTitle">Family Details</h2>
            <button type="button" class="icon-btn" data-close-modal aria-label="Close">&times;</button>
        </div>

        {{-- Status line. Populated by JS; the badge carries its own text, never
             colour alone. --}}
        <p class="mb-3 flex flex-wrap items-center gap-x-3 gap-y-1" id="hv-summary"></p>

        <dl class="mb-4 grid grid-cols-1 gap-x-6 gap-y-2 sm:grid-cols-2">
            <div>
                <dt class="text-sm font-semibold text-ink-soft">Household ID</dt>
                <dd class="font-mono" id="hv-code">&mdash;</dd>
            </div>
            <div>
                <dt class="text-sm font-semibold text-ink-soft">Household head</dt>
                <dd id="hv-head">&mdash;</dd>
                {{-- PHASE 9 ITEM 2. A second <dd> under the same <dt>, which is
                     valid in a <dl> and keeps the stand-in visually attached to
                     the head it stands in for. Revealed by staff.js only when
                     one is designated. --}}
                <dd id="hv-acting" class="text-sm text-ink-soft" hidden></dd>
            </div>
            <div>
                <dt class="text-sm font-semibold text-ink-soft">Origin barangay</dt>
                <dd id="hv-barangay">&mdash;</dd>
            </div>
            <div>
                <dt class="text-sm font-semibold text-ink-soft">Family address</dt>
                <dd id="hv-address">&mdash;</dd>
            </div>
            <div>
                <dt class="text-sm font-semibold text-ink-soft">Shelter</dt>
                <dd id="hv-center">&mdash;</dd>
            </div>
            <div>
                <dt class="text-sm font-semibold text-ink-soft">Checked in</dt>
                <dd id="hv-checked-in">&mdash;</dd>
            </div>
            <div>
                <dt class="text-sm font-semibold text-ink-soft">Checked out</dt>
                <dd id="hv-checked-out">&mdash;</dd>
            </div>
            <div>
                <dt class="text-sm font-semibold text-ink-soft">Present</dt>
                <dd class="font-mono" id="hv-present">&mdash;</dd>
            </div>
        </dl>

        <h3 class="mb-2 text-base font-semibold">Members</h3>

        {{-- data-stack + data-label on every cell, exactly like every other
             table in the system, so this reads as a card list on a phone. --}}
        <div class="table-panel">
            <table class="data-table" data-stack>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Date of birth</th>
                        <th>Age group</th>
                        <th>Sex</th>
                        <th>Presence</th>
                        <th>Categories</th>
                    </tr>
                </thead>
                <tbody id="hv-members"></tbody>
            </table>
        </div>

        {{-- Read-only: Close is the ONLY control. No Save, no Edit shortcut. A
             way out of a viewer into an editor is how someone ends up editing a
             record they opened to read. --}}
        <div class="modal-actions flex flex-col gap-2 sm:flex-row sm:justify-end">
            <button type="button" class="btn-secondary" data-close-modal>Close</button>
        </div>
    </div>
</div>
