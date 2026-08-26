import { wireAgeGroup, tierShortLabel } from './age-tiers';
// PHASE 4 item B.4: initTheme() moved to its own module so the public bundle
// can have it without pulling in this file. Behaviour is unchanged.
import { initTheme } from './theme';
// EvacTech staff UI behavior. No build step assumptions beyond Vite bundling this file.

document.addEventListener('DOMContentLoaded', () => {
    initTheme();
    initSidebarCollapse();
    initMobileNav();
    initConnectivity();
    initModals();
    initConfirmForms();
    initDeleteHousehold();
    initEvacueeForm();
    initHouseholdView();
    initShelterModals();
    initReliefModals();
});

// ---------------------------------------------------------------------
// Connectivity indicator
// ---------------------------------------------------------------------
// Replaces a "Live Updates Active" badge that was decoration: there is no
// setInterval, no EventSource and no websocket anywhere in this codebase, so
// nothing on any page updates itself. Telling barangay staff that an occupancy
// figure is live when it is a page-load snapshot is the kind of claim that gets
// relied on during a flood.
//
// navigator.onLine is honest about what it knows and no more -- it reports that
// the device has a network interface, not that the server is reachable. So
// "Connected" makes no promise about the figures, and going offline surfaces the
// time the page was rendered, which is the fact that actually matters when the
// numbers on screen have stopped being current.
function initConnectivity() {
    const pill = document.getElementById('connectivityPill');
    const text = document.getElementById('connectivityText');
    if (!pill || !text) return;

    const renderedAt = pill.dataset.renderedAt || '';

    const render = () => {
        const online = navigator.onLine;
        pill.dataset.conn = online ? 'online' : 'offline';
        text.textContent = online
            ? 'Connected'
            : `Offline${renderedAt ? ` \u00B7 last updated ${renderedAt}` : ''}`;
    };

    render();
    window.addEventListener('online', render);
    window.addEventListener('offline', render);
}

// Theme toggle now lives in ./theme.js -- see the import at the top of this
// file. It is still called from the DOMContentLoaded block above.

// ---------------------------------------------------------------------
// Sidebar collapse (roadmap item 13)
// ---------------------------------------------------------------------
// The collapsed state persists in a `sidebar` cookie and is applied SERVER
// SIDE in the layout, so a collapsed sidebar never flashes open on navigation.
// The cookie is excluded from encryptCookies in bootstrap/app.php, same as
// `theme` -- an encrypted value cannot be read from Blade.
function initSidebarCollapse() {
    const btn = document.getElementById('collapseToggle');
    const shell = document.querySelector('.staff-shell');
    if (!btn || !shell) return;

    const sync = () => {
        const collapsed = shell.classList.contains('sidebar-collapsed');
        btn.textContent = collapsed ? '\u27E9' : '\u27E8';
        btn.setAttribute('aria-label', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
        btn.setAttribute('aria-pressed', collapsed ? 'true' : 'false');
    };

    sync();

    btn.addEventListener('click', () => {
        shell.classList.toggle('sidebar-collapsed');
        const collapsed = shell.classList.contains('sidebar-collapsed');
        document.cookie = `sidebar=${collapsed ? 'collapsed' : 'expanded'};path=/;max-age=31536000;samesite=lax`;
        sync();
    });
}

// ---------------------------------------------------------------------
// Mobile navigation drawer (roadmap item 13)
// ---------------------------------------------------------------------
// Below 1024px the sidebar becomes an off-canvas drawer. Barangay staff work
// from phones, so this is the primary navigation path, not a fallback -- the
// previous stylesheet simply hid the sidebar under 720px and left a phone with
// no navigation at all.
//
// The drawer is inert while closed so keyboard focus cannot wander into an
// invisible menu, and focus moves to the first link on open.
function initMobileNav() {
    const toggle = document.getElementById('mobileNavToggle');
    const sidebar = document.getElementById('sidebar');
    const backdrop = document.getElementById('sidebarBackdrop');
    if (!toggle || !sidebar) return;

    const setState = (open) => {
        document.body.classList.toggle('mobile-nav-open', open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        sidebar.setAttribute('aria-hidden', open ? 'false' : 'true');
        if (backdrop) backdrop.hidden = !open;
        // Stop the page behind the drawer scrolling under the user's thumb.
        document.body.style.overflow = open ? 'hidden' : '';
        if (open) sidebar.querySelector('a, button')?.focus();
    };

    // Desktop shows the sidebar permanently, so the drawer state and its
    // aria-hidden must be cleared above the breakpoint or the sidebar would be
    // announced as hidden while plainly visible.
    const desktop = window.matchMedia('(min-width: 1024px)');
    const applyBreakpoint = () => {
        if (desktop.matches) {
            document.body.classList.remove('mobile-nav-open');
            document.body.style.overflow = '';
            sidebar.removeAttribute('aria-hidden');
            if (backdrop) backdrop.hidden = true;
            toggle.setAttribute('aria-expanded', 'false');
        } else {
            sidebar.setAttribute('aria-hidden', document.body.classList.contains('mobile-nav-open') ? 'false' : 'true');
        }
    };

    applyBreakpoint();
    desktop.addEventListener('change', applyBreakpoint);

    toggle.addEventListener('click', () => {
        setState(!document.body.classList.contains('mobile-nav-open'));
    });

    backdrop?.addEventListener('click', () => setState(false));

    document.getElementById('mobileNavClose')?.addEventListener('click', () => {
        setState(false);
        toggle.focus();
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && document.body.classList.contains('mobile-nav-open')) {
            setState(false);
            toggle.focus();
        }
    });
}

// ---------------------------------------------------------------------
// Active shelter switcher -- REMOVED IN PHASE 6 (item 7)
// ---------------------------------------------------------------------
// initShelterSwitcher() lived here and submitted #shelterSwitchForm on change.
// Both the form and the sticky strip that held it are gone from
// layouts/staff.blade.php: a staff member is physically inside one shelter for
// one shift, and moving them to another is a reassignment made by City Admin.
// ResolvesCenter::resolveCenter() already falls back to the first roster entry
// when the stored session id is no longer on the user's roster, so a reassigned
// account lands on its new shelter with nothing to click.
//
// The function was deleted rather than left to early-return. A no-op that looks
// live is the thing that makes the next person debug the wrong file.

// ---------------------------------------------------------------------
// Generic modal open/close (data-open-modal="id" / data-close-modal)
// ---------------------------------------------------------------------
/* PHASE 8 ITEM 2. openModal() now announces itself.

   The pre-filled pickers need to run a search the moment their modal appears,
   and a modal appears two ways: a click on [data-open-modal], and the ?open=
   deep links (`?open=checkin`, `?open=distribute`, `?open=register`), which
   never produce a click at all. A delegated click listener would catch the first
   and silently miss the second, which is precisely the class of half-working
   feature this codebase keeps paying for.

   One event covers both, because every path already funnels through this
   function. It bubbles so a listener may sit on `document`; nothing listens on
   the modals that do not need it, and dispatching into an empty room is free. */
function openModal(id) {
    const el = document.getElementById(id);
    if (!el) {
        console.error(`[EvacTech/staff] openModal("${id}") found no such element.`);
        return;
    }
    el.hidden = false;
    el.dispatchEvent(new CustomEvent('evactech:modal-open', { bubbles: true }));
}
/* PHASE 9 ITEM 1 -- say WHICH member matched, when it was not the head.

   Item 1 widened every household search from "head only" to "any member", which
   creates a question the old search never could: a search for "Maria" now
   returns a row reading "Dela Cruz, Juan", and nothing on that row explains why.
   The server sends `matched` only when the match was somebody other than the
   head, so this appends nothing on the ordinary path.

   Used by all three pickers in this module -- check-in, relief distribution and
   special request -- so the phrasing cannot drift between them. */
function matchedSuffix(item) {
    return item && item.matched ? ' \u00B7 matched: ' + item.matched : '';
}

function closeModal(id) {
    const el = document.getElementById(id);
    if (el) el.hidden = true;
}
function initModals() {
    document.querySelectorAll('[data-open-modal]').forEach((btn) => {
        btn.addEventListener('click', () => openModal(btn.dataset.openModal));
    });
    document.querySelectorAll('[data-close-modal]').forEach((btn) => {
        btn.addEventListener('click', () => btn.closest('.modal-backdrop').hidden = true);
    });
    document.querySelectorAll('.modal-backdrop').forEach((backdrop) => {
        backdrop.addEventListener('click', (e) => {
            if (e.target === backdrop) backdrop.hidden = true;
        });
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal-backdrop:not([hidden])').forEach((m) => m.hidden = true);
        }
    });
}

// ---------------------------------------------------------------------
// Confirm-before-submit for destructive actions (data-confirm="message")
// ---------------------------------------------------------------------
function initConfirmForms() {
    document.querySelectorAll('form[data-confirm]').forEach((form) => {
        form.addEventListener('submit', (e) => {
            if (!confirm(form.dataset.confirm)) e.preventDefault();
        });
    });
}

// ---------------------------------------------------------------------
// PHASE 7 ITEM 1 -- Remove Household confirmation
// ---------------------------------------------------------------------
// Deleting a family is the only irreversible destructive action a barangay
// operator can take, and it takes every member record with it. It used to be a
// browser confirm() carrying the household code and nothing else -- which asks
// someone to approve a deletion without showing them what they are deleting.
// One mis-tapped row on a phone and the wrong family is gone.
//
// So: a real modal that names the head, the code and the family size, reusing
// .modal-backdrop / .modal / .modal-actions rather than adding CSS.
//
// The other eight data-confirm sites keep the plain dialog on purpose. Check-in,
// check-out, activate, deactivate and maintenance mode are all reversible; a
// dialog is the right weight for those and escalating them all would train
// people to click through the one that matters.
//
// Delegated on document, not bound per button: the household rows are paginated
// and this stays correct however the table is rendered.
function initDeleteHousehold() {
    const modal = document.getElementById('deleteHouseholdModal');
    if (!modal) return; // not the Evacuee Profiling screen

    const form = document.getElementById('deleteHouseholdForm');
    const submit = document.getElementById('dh-submit');
    const blocked = document.getElementById('dh-blocked');
    const template = modal.dataset.urlTemplate;

    if (!form || !submit || !blocked || !template) {
        console.warn('EvacTech delete-household: the modal is present but missing form, submit button, blocked note or data-url-template; falling back to no delete.');
        return;
    }

    document.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-delete-household]');
        if (!btn) return;

        const d = btn.dataset;
        setText('dh-code', d.hhCode);
        setText('dh-head', d.hhHead);
        setText('dh-members', d.hhMembers);

        // destroy() refuses to delete a checked-in household anyway. Saying so
        // here, before the round trip, turns a validation error into an
        // instruction: check them out first.
        const isCheckedIn = d.hhCheckedIn === '1';
        blocked.hidden = !isCheckedIn;
        submit.disabled = isCheckedIn;

        form.action = template.replace(':id', d.deleteHousehold);
        openModal('deleteHouseholdModal');
    });
}

function setText(id, value) {
    const el = document.getElementById(id);
    if (el) el.textContent = value || '\u2014';
}

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content;
}

function ageFromBirthdate(dateStr) {
    if (!dateStr) return null;
    const dob = new Date(dateStr);
    if (isNaN(dob)) return null;
    const today = new Date();
    let age = today.getFullYear() - dob.getFullYear();
    const m = today.getMonth() - dob.getMonth();
    if (m < 0 || (m === 0 && today.getDate() < dob.getDate())) age--;
    return age;
}

// Phase 2: the old three-label helper (Infant / Adult / Senior) is gone. Those
// were classifications; they are seven derived age tiers now. See age-tiers.js,
// which mirrors app/Support/AgeTier.php exactly.
function ageTagLabel(tierKey) {
    return tierShortLabel(tierKey);
}

// ---------------------------------------------------------------------
// Add / Edit Evacuee form: dynamic member rows + auto age tag + edit prefill
// ---------------------------------------------------------------------
function initEvacueeForm() {
    const modal = document.getElementById('evacueeModal');
    if (!modal || !window.EvacueeConfig) return;

    const template = document.getElementById('memberRowTemplate');
    const headRow = document.getElementById('headRow');
    const memberRows = document.getElementById('memberRows');
    const addBtn = document.getElementById('addMemberBtn');
    const form = document.getElementById('evacueeForm');
    const title = document.getElementById('evacueeModalTitle');
    let memberIndex = 1; // 0 reserved for head

    function makeRow(fieldPrefix, isHead) {
        const node = template.content.cloneNode(true);
        const row = node.querySelector('[data-row]');
        row.querySelectorAll('[data-field]').forEach((el) => {
            const field = el.dataset.field;
            // PHASE 2 BUG FIX. This used to emit `members[0][tags[]]`. PHP's
            // parser closes the key at the FIRST ']', so it became the string
            // key 'tags[' and the trailing ']' was discarded -- meaning
            // members.*.tags never arrived, validation passed because the rule
            // is nullable, and every manually ticked classification was silently
            // dropped. The only tags that ever saved were the ones the server
            // stamped on automatically. Correct form is `members[0][tags][]`.
            el.name = `members[${fieldPrefix}][${field}]` + (field === 'tags' ? '[]' : '');
        });
        if (isHead) {
            row.querySelector('[data-field="birthdate"]').closest('.member-row')?.classList.add('is-head');
            const removeBtn = row.querySelector('[data-remove-row]');
            removeBtn.remove(); // can't remove the head row
            const hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = `members[${fieldPrefix}][is_head]`;
            hidden.value = '1';
            row.appendChild(hidden);
        } else {
            row.querySelector('[data-remove-row]').addEventListener('click', () => row.remove());

            // PHASE 3 ITEM 11b -- close the last half of item 9's surname
            // inheritance. The row template carries required= because the SAME
            // template is cloned for the head row, where a surname really is
            // mandatory. On a MEMBER row the server rule is nullable (a blank
            // surname is filled from the head's inside HouseholdMemberSync), so
            // leaving the attribute on means the browser blocks the submit
            // before the relaxed rule is ever reached: server says optional,
            // browser says required. Stripping it HERE rather than in the
            // template is what keeps the head row strict.
            row.querySelector('[data-field="last_name"]')?.removeAttribute('required');
        }

        // Birthdate <-> age-group wiring. Birthdate wins and locks the
        // dropdown; with no birthdate the dropdown is the operator's fast
        // tag-first path and validation requires it.
        wireAgeGroup(row);

        return row;
    }

    function resetForm() {
        form.reset();
        // PHASE 6 -- clear any head-transfer confirmation left from a previous
        // session of this modal, so it cannot reappear over a different family.
        const note = document.getElementById('evacueeNote');
        if (note) { note.hidden = true; note.textContent = ''; }
        headRow.innerHTML = '';
        memberRows.innerHTML = '';
        memberIndex = 1;
        headRow.appendChild(makeRow(0, true));
        title.textContent = 'Register Household';
        // Origin barangay defaults to the barangay of the active shelter (the common
        // case) but stays editable -- one shelter takes families from several.
        const brgy = document.getElementById('ev-barangay');
        if (brgy && window.EvacueeConfig.defaultBarangayId) {
            brgy.value = String(window.EvacueeConfig.defaultBarangayId);
        }
        form.action = window.EvacueeConfig.storeUrl;
        document.getElementById('evacueeFormMethod').value = 'POST';
        const transferBtn = document.getElementById('transferHeadBtn');
        if (transferBtn) transferBtn.hidden = true;
    }

    /* PHASE 3 ITEM 9 -- surname inheritance, client side.
       The server fills a blank member surname from the head's inside
       HouseholdMemberSync; that is the guarantee. This is the affordance: the
       operator SEES the inherited name and can type over it, so a mixed-surname
       family is corrected before saving rather than discovered afterwards. */
    function headLastNameInput() {
        return headRow.querySelector('[data-field="last_name"]');
    }

    function memberLastNameInputs() {
        return memberRows.querySelectorAll('[data-field="last_name"]');
    }

    /* Fill only the blanks. A surname the operator typed is never overwritten,
       so correcting one child's name and then fixing a typo in the head's does
       not silently undo the correction. */
    function fillBlankSurnames() {
        const head = headLastNameInput();
        if (!head) return;
        const surname = head.value.trim();
        if (surname === '') return;

        memberLastNameInputs().forEach((input) => {
            if (input.value.trim() === '') input.value = surname;
        });
    }

    addBtn.addEventListener('click', () => {
        const row = makeRow(memberIndex, false);
        const head = headLastNameInput();
        const surname = head ? head.value.trim() : '';
        if (surname !== '') {
            const field = row.querySelector('[data-field="last_name"]');
            if (field) field.value = surname;
        }
        memberRows.appendChild(row);
        memberIndex++;
    });

    /* Delegated on the form, not bound to the head input directly: resetForm()
       and loadHouseholdIntoForm() both replace headRow's contents wholesale, so
       a listener attached to the input itself would be thrown away with it. */
    form.addEventListener('blur', (e) => {
        if (typeof e.target.matches !== 'function') return;
        if (!e.target.matches('[data-field="last_name"]')) return;

        // Leaving the HEAD's surname: push it down into every blank member row.
        if (headRow.contains(e.target)) {
            fillBlankSurnames();
            return;
        }

        // Leaving a MEMBER's surname: pull the head's down, but only into a
        // field the operator left empty. A deliberately different surname --
        // a married daughter, a fostered child -- is never overwritten.
        if (!memberRows.contains(e.target)) return;
        if (e.target.value.trim() !== '') return;

        const head = headLastNameInput();
        const surname = head ? head.value.trim() : '';
        if (surname !== '') e.target.value = surname;
    }, true);

    document.querySelectorAll('[data-open-modal="evacueeModal"]').forEach((btn) => {
        btn.addEventListener('click', async () => {
            resetForm();
            const householdId = btn.dataset.household;
            if (householdId) {
                await loadHouseholdIntoForm(householdId);
            }
        });
    });

    async function loadHouseholdIntoForm(id) {
        title.textContent = 'Edit Family';
        const url = window.EvacueeConfig.showUrlTemplate.replace(':id', id);
        const res = await fetch(url, { headers: { Accept: 'application/json' } });
        if (!res.ok) return;
        const data = await res.json();

        document.getElementById('ev-address').value = data.address || '';
        /* DROP 1. Without this, form.reset() clears the box and the next save
           writes is_separated = false -- silently discarding a declared fact
           about somebody's family.

           Guarded, because a querySelector line pointing at markup that is not
           there throws on the FIRST one and silently abandons the rest of the
           function. That is the bug that hid three features when Facilities was
           removed. */
        const evSep = document.getElementById('ev-separated');
        if (evSep) evSep.checked = !!data.is_separated;
        const brgySelect = document.getElementById('ev-barangay');
        if (brgySelect && data.origin_barangay_id) {
            brgySelect.value = String(data.origin_barangay_id);
        }
        headRow.innerHTML = '';
        memberRows.innerHTML = '';
        memberIndex = 1;

        data.members.forEach((m) => {
            const idx = m.is_head ? 0 : memberIndex++;
            const row = makeRow(idx, m.is_head);
            row.querySelector('[data-field="id"]').value = m.id;
            const [last, firstMiddle] = (m.full_name || '').split(',').map((s) => s.trim());
            row.querySelector('[data-field="last_name"]').value = last || '';
            row.querySelector('[data-field="first_name"]').value = (firstMiddle || '').split(' ')[0] || '';
            row.querySelector('[data-field="birthdate"]').value = m.birthdate || '';
            row.querySelector('[data-field="sex"]').value = m.sex || '';
            // Categories are checkboxes now, not a 1-row-tall multi-select.
            const tagIds = (m.tags || []).map((t) => String(t.id));
            row.querySelectorAll('[data-field="tags"]').forEach((box) => {
                box.checked = tagIds.includes(box.value);
            });

            // PHASE 7 item 2. Dispatched AFTER the tag checkboxes are restored, and
            // that order is load-bearing: sex-fields.js unticks the female-only boxes
            // when the sex is not female, so running it first would let the tag loop
            // immediately re-tick a hidden box and post a value the server rejects.
            // Dispatched rather than called because sex-fields.js listens by
            // delegation on document; a programmatic value assignment fires nothing.
            row.querySelector('[data-field="sex"]').dispatchEvent(new Event('change', { bubbles: true }));

            // Restore the manually chosen group, then let wireAgeGroup settle
            // the lock/badge state from whatever the birthdate turns out to be.
            const groupSelect = row.querySelector('[data-field="age_group"]');
            if (groupSelect) groupSelect.value = m.age_tier_fallback || m.age_tier || '';
            wireAgeGroup(row);

            (m.is_head ? headRow : memberRows).appendChild(row);
        });

        form.action = window.EvacueeConfig.updateUrlTemplate.replace(':id', id);
        document.getElementById('evacueeFormMethod').value = 'PUT';
        const methodInput = document.createElement('input');
        methodInput.type = 'hidden';
        methodInput.name = '_method';
        methodInput.value = 'PUT';
        form.appendChild(methodInput);

        const transferBtn = document.getElementById('transferHeadBtn');
        if (transferBtn) {
            const canTransfer = data.members.length > 1;
            transferBtn.hidden = !canTransfer;
            transferBtn.onclick = () => {
                if (window.EvacTech?.openTransferFlow) window.EvacTech.openTransferFlow(data);
            };
        }
    }

    resetForm();

    /* PHASE 6 -- bridge for the head-transfer flow.

       initShelterModals() owns the transfer modals, but the form they operate on
       belongs to THIS function, and loadHouseholdIntoForm is closed over here.
       Rather than lift the function to module scope -- where it would need the
       config, the row template and the tag list dragged with it -- the one entry
       point the transfer flow needs is published on the same window.EvacTech
       object that already carries openTransferFlow in the other direction.

       Deliberately narrow: it repopulates the open form for one household id and
       returns a promise, and it is the ONLY thing exposed. */
    window.EvacTech = window.EvacTech || {};
    window.EvacTech.reloadHouseholdIntoForm = (id) => loadHouseholdIntoForm(id);

    /* DROP D. The check-in picker's 'separated' row needs a blank register form,
       and resetForm() is scoped to this initialiser. Published on the SAME
       bridge object rather than made a global, for the reason the whole file
       exists: openModal/closeModal are module-scoped and calling one by bare
       name from elsewhere throws ReferenceError.

       Optionally seeds the head row's name. full_name is stored "Last, First
       Middle" -- the same split loadHouseholdIntoForm() uses -- so a prefill
       that guessed differently would put a surname in the wrong box. */
    window.EvacTech.resetEvacueeFormFor = (fullName) => {
        resetForm();
        if (!fullName) return;

        const [last, firstMiddle] = String(fullName).split(',').map((x) => x.trim());
        const lastEl = headRow.querySelector('[data-field="last_name"]');
        const firstEl = headRow.querySelector('[data-field="first_name"]');

        /* Guard every lookup. A querySelector(...).value line pointing at markup
           that is not there throws on the FIRST one and silently abandons the
           rest of the function -- the bug that hid three features when
           Facilities was removed. */
        if (lastEl) lastEl.value = last || '';
        if (firstEl) firstEl.value = firstMiddle || '';
    };

    // Support deep-link ?edit={id} from the Shelter page's "Edit Family" action.
    const params = new URLSearchParams(window.location.search);
    const deepLinkId = params.get('edit');
    if (deepLinkId) {
        openModal('evacueeModal');
        loadHouseholdIntoForm(deepLinkId);

        /* PHASE 6 -- "the modal will not close after saving".
           It closed. The page reloaded and reopened it.

           EvacueeProfilingController::update() ends in redirect()->back(), and
           Laravel's back() prefers the Referer header, which was still
           .../evacuees?edit=123 -- the URL this deep link arrived on. So the
           save round-tripped straight back to a URL whose only instruction is
           "open the edit modal", and the operator saw a Save button that
           apparently did nothing. The head-transfer confirm did the same thing,
           which is where it was first noticed.

           Dropping the parameter from the address bar the moment it has been
           consumed fixes it at the source: the next Referer is the clean URL,
           back() lands on the list, and a manual refresh no longer reopens a
           modal the operator already finished with. replaceState leaves no
           history entry, so Back still goes where the operator expects.

           Drop 3 removes the deep link entirely by opening this modal in place
           on the Shelter page. This stays correct either way. */
        stripQueryParam('edit');
    }
    if (window.EvacueeConfig.autoOpen) {
        openModal('evacueeModal');
        /* PHASE 6 ITEM 11. Same trap the ?edit= deep link had, and it becomes
           live the moment store() switches to redirect()->back(): the Referer
           would still carry ?open=register, so saving a new household would
           reload into a URL whose only instruction is "open the register
           modal", and the operator would watch a blank form reappear over the
           family they just saved.

           Dropping the parameter once it has been consumed leaves the next
           Referer clean. replaceState adds no history entry. */
        stripQueryParam('open');
    }
}

/* Removes one query parameter from the address bar without navigating or adding
   a history entry. Used for the one-shot deep links (?edit=, ?open=) that must
   not survive into the Referer of the next form post. */
function stripQueryParam(name) {
    const params = new URLSearchParams(window.location.search);
    if (!params.has(name)) return;
    params.delete(name);
    const query = params.toString();
    window.history.replaceState({}, '', window.location.pathname + (query ? '?' + query : '') + window.location.hash);
}

// ---------------------------------------------------------------------
// Shelter page: Check-in Family, Select New Family Head, Confirm Transfer
// ---------------------------------------------------------------------
// ---------------------------------------------------------------------
// Read-only household view (Phase 6 item 10)
// ---------------------------------------------------------------------
// Renders partials/household-view-modal from the existing
// `barangay.evacuees.show` payload. Read only: it writes with textContent and
// builds every node with createElement, so a household address or a member name
// can never be interpreted as markup.
//
// Bootstrapped by delegation on `document`, not by querying the buttons at load
// time, so it keeps working if a table is ever re-rendered.
function initHouseholdView() {
    const modal = document.getElementById('householdViewModal');
    if (!modal) return;

    /* PHASE 6 ITEM 10. Its own config object, with EvacueeConfig as a fallback.

       City Admin needs View too, but its screens have no evacuee EDIT form --
       setting window.EvacueeConfig there just to carry one URL would make
       initEvacueeForm() try to run and hunt for #evacueeForm, #headRow and
       #memberRows that do not exist. A separate object keeps the viewer
       independent of the editor, and the barangay pages keep working through
       the fallback without setting anything new.

       The URL differs per role by design: barangay uses
       barangay.evacuees.show, City Admin uses its own city-wide or
       per-shelter endpoint. The partial itself contains no route() call. */
    const viewUrlTemplate = window.HouseholdViewConfig?.showUrlTemplate
        || window.EvacueeConfig?.showUrlTemplate;

    if (!viewUrlTemplate) {
        console.error('[EvacTech/staff] the household view modal is on this page but neither window.HouseholdViewConfig.showUrlTemplate nor window.EvacueeConfig.showUrlTemplate is set, so View can never load anything.');
        return;
    }

    const AGE_UNKNOWN = 'Unknown';

    function text(el, value) {
        document.getElementById(el).textContent = (value === null || value === undefined || value === '') ? '\u2014' : value;
    }

    // The payload carries ISO 8601; formatting is the view's job. Falls back to
    // the raw string rather than printing "Invalid Date" if it ever changes.
    function formatDate(iso) {
        if (!iso) return null;
        const d = new Date(iso);
        if (Number.isNaN(d.getTime())) return iso;
        return d.toLocaleString(undefined, {
            year: 'numeric', month: 'short', day: '2-digit',
            hour: '2-digit', minute: '2-digit',
        });
    }

    function cell(row, label, value) {
        const td = document.createElement('td');
        td.setAttribute('data-label', label);
        if (value !== undefined) td.textContent = value;
        row.appendChild(td);
        return td;
    }

    function badge(cls, label) {
        const span = document.createElement('span');
        span.className = 'badge ' + cls;
        span.textContent = label;
        return span;
    }

    function render(data) {
        text('hv-code', data.code);
        text('hv-address', data.address);
        text('hv-barangay', data.origin_barangay);
        text('hv-center', data.center);
        text('hv-checked-in', formatDate(data.checked_in_at));
        text('hv-checked-out', formatDate(data.checked_out_at));

        const head = data.members.find((m) => m.is_head);
        text('hv-head', head ? head.full_name : null);

        /* PHASE 9 ITEM 2. Shown whenever a stand-in is designated -- including
           after staff chose to KEEP one once the head arrived, which is why this
           does not test the head's presence to decide whether to render. It
           tests presence only to word the line. */
        const acting = document.getElementById('hv-acting');
        if (acting) {
            if (data.acting_head_member_id) {
                const name = data.acting_head || 'A member';
                acting.textContent = data.head_is_present
                    ? 'Standing in: ' + name
                    : 'Standing in: ' + name + ' \u00B7 head not present';
                acting.hidden = false;
            } else {
                acting.textContent = '';
                acting.hidden = true;
            }
        }

        const present = document.getElementById('hv-present');
        present.textContent = (data.members_present ?? 0) + ' / ' + (data.number_of_members ?? data.members.length);

        // Status is never colour alone -- the badge prints its own words.
        const summary = document.getElementById('hv-summary');
        summary.innerHTML = '';
        const statusLabel = String(data.status || '').replace(/_/g, ' ');
        const statusClass = data.status === 'checked_in'
            ? 'badge-success'
            : (data.status === 'checked_out' ? 'badge-warning' : 'badge-info');
        if (statusLabel) summary.appendChild(badge(statusClass, statusLabel.charAt(0).toUpperCase() + statusLabel.slice(1)));
        // Derived from members_present == 1 on a checked-in family. A
        // household-level fact, not a vulnerable classification -- which is why
        // it sits here and not in the member Categories column.
        if (data.single_headed) summary.appendChild(badge('badge-warning', 'Single-headed'));

        const body = document.getElementById('hv-members');
        body.innerHTML = '';

        // Head first, then everyone else in payload order, so the family reads
        // the same way it does in the edit form.
        const ordered = data.members.slice().sort((a, b) => (b.is_head ? 1 : 0) - (a.is_head ? 1 : 0));

        ordered.forEach((m) => {
            const row = document.createElement('tr');

            const nameCell = cell(row, 'Name');
            nameCell.textContent = m.full_name;
            if (m.is_head) nameCell.appendChild(badge('badge-info', 'Head'));

            cell(row, 'Date of birth', m.birthdate || '\u2014');
            // An Unknown bucket is required: a member with neither a birthdate
            // nor a chosen group must not silently read as an adult.
            cell(row, 'Age group', m.age_tier_label || AGE_UNKNOWN);
            cell(row, 'Sex', m.sex ? m.sex.charAt(0).toUpperCase() + m.sex.slice(1) : '\u2014');

            const presenceCell = cell(row, 'Presence');
            presenceCell.appendChild(
                m.is_present ? badge('badge-success', 'Present') : badge('badge-warning', 'Not present')
            );

            const tagCell = cell(row, 'Categories');
            if (!m.tags || m.tags.length === 0) {
                tagCell.textContent = 'None';
                tagCell.classList.add('text-muted');
            } else {
                const wrap = document.createElement('span');
                wrap.className = 'flex flex-wrap gap-1';
                m.tags.forEach((t) => wrap.appendChild(badge('badge-info', t.name)));
                tagCell.appendChild(wrap);
            }

            body.appendChild(row);
        });
    }

    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('[data-view-household]');
        if (!btn) return;

        const id = btn.dataset.viewHousehold;
        if (!id) {
            console.error('[EvacTech/staff] a View control has no data-view-household id.');
            return;
        }

        openModal('householdViewModal');
        document.getElementById('hv-members').innerHTML = '';
        document.getElementById('hv-summary').textContent = 'Loading\u2026';

        try {
            const url = viewUrlTemplate.replace(':id', id);
            const res = await fetch(url, { headers: { Accept: 'application/json' } });
            if (!res.ok) {
                console.error('[EvacTech/staff] household view request returned status ' + res.status + ' for household ' + id);
                document.getElementById('hv-summary').textContent = 'This family could not be loaded.';
                return;
            }
            render(await res.json());
        } catch (err) {
            console.error('[EvacTech/staff] household view request failed.', err);
            document.getElementById('hv-summary').textContent = 'This family could not be loaded.';
        }
    });
}

function initShelterModals() {
    if (!window.ShelterConfig) return;
    const cfg = window.ShelterConfig;

    const searchInput = document.getElementById('ci-search');
    const loadBtn = document.getElementById('ci-load');
    const resultsList = document.getElementById('ci-results');
    const checkinForm = document.getElementById('checkinForm');
    let currentHousehold = null;

    /* PHASE 8 ITEM 2. `if (!term) { resultsList.hidden = true; return; }` used to
       be the first line here, and it was the ONLY reason this list started
       empty. The endpoint has always returned its first ten rows for a blank
       term -- Barangay\EvacueeProfilingController::search() wraps the name match
       in when($term, ...) rather than requiring it -- so refusing to call it
       meant staff had to guess a name before the system would show them the
       families it already knew about.

       Now a blank term is a legitimate search meaning "show me the recent ones",
       and prefill() below fires one the moment the modal opens. */
    /* PHASE 9 ITEMS 3 + 5 -- one row, three possible destinations.

       Staff reach for Check-in whatever the situation is, because that is what
       the button is called. Before this, a family already checked in here with
       one member still to arrive was simply absent from the list, and a family
       checked in somewhere else could be checked in a second time -- moving them
       with no transfer record at all.

       The server decides which case a row is (Household::checkinAction), so the
       barangay and City Admin pickers cannot disagree, and so the rule sits
       beside the check-in guard that enforces it.

       'arrival' and 'transfer' rows are rendered as BUTTONS carrying
       data-presence / data-tx-create. transfers.js delegates both on document
       and is imported by app.js, so it is already listening on this page -- which
       is how one click can cross an ES module boundary without calling
       show() or openModal() by bare name and throwing ReferenceError. */
    function buildCheckinRow(item) {
        const li = document.createElement('li');
        const label = `${item.head}${matchedSuffix(item)} \u00B7 ${item.size} members`;

        if (item.action === 'arrival') {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn-link text-left';
            btn.dataset.presence = item.id;
            const n = Number(item.absent) || 0;
            btn.textContent = `${label} \u00B7 already checked in here, ${n} not yet arrived `
                + '\u00B7 Record arrival';
            /* The check-in modal must close, and it cannot close itself:
               data-close-modal is bound at init over the elements that existed
               then, so an attribute on a row built now would never fire. */
            btn.addEventListener('click', () => closeModal('checkinModal'));
            li.appendChild(btn);
            return li;
        }

        /* DROP B. A person listed with a family at another shelter but NOT
           present there is one person with two places to be, not a family that
           needs moving. Offering Transfer here would move their whole family.

           Deliberately NOT a button. The operator's next step is to register a
           new household, which lives in a different modal, and a cross-modal
           shortcut is more machinery than this is worth. The row states the
           situation and stops the wrong action, which is the actual harm. */
        if (item.action === 'separated') {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn-link text-left';
            btn.textContent = `${label} \u00B7 listed with a family at `
                + `${item.center || 'another shelter'} but not present there `
                + '\u00B7 Register them here as a new household';
            /* DROP D. This was plain text and it was a dead end: it told the
               operator what to do and gave them no way to do it. Check-in
               cannot help -- it acts on households that already exist, and this
               person needs a NEW one -- so the row hands over to the register
               form instead, with their name already in it.

               The check-in modal must be closed explicitly. data-close-modal is
               bound at init over elements that existed then, so an attribute on
               a row built now would never fire. */
            btn.addEventListener('click', () => {
                closeModal('checkinModal');
                openModal('evacueeModal');
                if (window.EvacTech?.resetEvacueeFormFor) {
                    window.EvacTech.resetEvacueeFormFor(item.separated_name || '');
                } else {
                    console.error('[EvacTech/staff] the evacuee modal is not on this page '
                        + '(window.EvacueeConfig missing), so the separated-member row has '
                        + 'nothing to open. Include partials/evacuee-modal.');
                }
            });
            li.appendChild(btn);
            return li;
        }

        if (item.action === 'transfer') {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn-link text-left';
            btn.dataset.txCreate = '';
            btn.dataset.householdId = item.id;
            btn.dataset.householdCode = item.code || '';
            btn.dataset.householdHead = item.head || '';
            btn.dataset.householdPresent = item.members_present || 0;
            btn.dataset.centerName = item.center || '';
            btn.dataset.centerId = item.current_center_id || '';
            btn.textContent = `${label} \u00B7 checked in at ${item.center || 'another shelter'} `
                + '\u00B7 Move by Transfer';
            btn.addEventListener('click', () => closeModal('checkinModal'));
            li.appendChild(btn);
            return li;
        }

        li.textContent = `${label} \u00B7 ${String(item.status || '').replace('_', ' ')}`;
        li.addEventListener('click', () => loadHousehold(item.id));
        return li;
    }

    async function runSearch(term) {
        try {
            const res = await fetch(`${cfg.searchUrl}?q=${encodeURIComponent(term || '')}`, { headers: { Accept: 'application/json' } });
            if (!res.ok) {
                console.error(`[EvacTech/staff] check-in household search failed with HTTP ${res.status}.`);
                resultsList.hidden = true;
                return;
            }
            const items = await res.json();
            resultsList.innerHTML = '';
            items.forEach((item) => {
                resultsList.appendChild(buildCheckinRow(item));
            });
            if (items.length === 0) {
                const none = document.createElement('li');
                none.className = 'search-empty';
                /* Two different situations, two different sentences. On an empty
                   term this list IS the whole candidate set, so "no match" would
                   be a lie -- there is genuinely nobody to check in. */
                none.textContent = term
                    ? 'No household matches that name.'
                    : 'No household is available to check in. Register one first.';
                resultsList.appendChild(none);
            }
            resultsList.hidden = false;
        } catch (err) {
            console.error('[EvacTech/staff] check-in household search error.', err);
            resultsList.hidden = true;
        }
    }

    let debounce;
    if (searchInput) {
        searchInput.addEventListener('input', () => {
            clearTimeout(debounce);
            debounce = setTimeout(() => runSearch(searchInput.value.trim()), 250);
        });
    }
    if (loadBtn) loadBtn.addEventListener('click', () => runSearch(searchInput.value.trim()));

    /* PHASE 8 ITEM 2. Open the modal, see candidates. The list is no longer a
       reward for typing the right name first.

       The previously loaded family is cleared at the same time. Without this,
       reopening the modal showed a prefilled candidate list above a check-in
       form still holding the LAST family that was loaded -- two different
       households on screen at once, and the Check In button belonging to the
       one you could no longer see. */
    document.getElementById('checkinModal')?.addEventListener('evactech:modal-open', () => {
        if (checkinForm) checkinForm.hidden = true;
        currentHousehold = null;
        if (searchInput) searchInput.value = '';
        const acting = document.getElementById('ci-acting');
        const actingOptions = document.getElementById('ci-acting-options');
        if (acting) acting.hidden = true;
        if (actingOptions) actingOptions.innerHTML = '';
        runSearch('');
    });

    /* Delegated on document, so it reaches tick boxes that did not exist when
       this module ran. Gotcha 21: delegation cannot observe rows being cloned
       into the DOM, so the state is recomputed on every change rather than
       initialised once. */
    document.addEventListener('change', (e) => {
        if (!e.target.closest('#ci-members')) return;
        syncActingHead();
    });

    async function loadHousehold(id) {
        const url = cfg.showUrlTemplate.replace(':id', id);
        const res = await fetch(url, { headers: { Accept: 'application/json' } });
        const data = await res.json();
        currentHousehold = data;
        resultsList.hidden = true;

        document.getElementById('ci-code').textContent = data.code;
        document.getElementById('ci-head').textContent = data.members.find((m) => m.is_head)?.full_name || '\u2014';

        const membersEl = document.getElementById('ci-members');
        membersEl.innerHTML = '';
        data.members.forEach((m) => {
            const label = document.createElement('label');
            label.className = 'checkbox-row';
            label.innerHTML = `<input type="checkbox" name="present[]" value="${m.id}" checked> ${m.full_name}${m.tags.length ? ' \u2014 ' + m.tags.map(t => t.name).join(', ') : ''}`;
            membersEl.appendChild(label);
        });

        checkinForm.action = cfg.checkinUrlTemplate.replace(':id', id);
        checkinForm.hidden = false;

        // PHASE 9 ITEM 2. Everyone is ticked by default, so this normally hides
        // itself immediately -- but a family whose head is already recorded away
        // needs the prompt from the moment the form appears.
        syncActingHead();
    }

    /* PHASE 9 ITEM 2 -- the stand-in head prompt.

       Rebuilt from the LIVE tick state every time it changes, rather than once
       when the family loads, because the operator decides who is present by
       unticking people and the head is often the last one they untick.

       The radio group only ever offers members who are currently ticked. The
       server enforces the same thing -- a stand-in must be present -- but a
       picker that can offer an invalid answer is a picker that will eventually
       be given one. */
    function syncActingHead() {
        const block = document.getElementById('ci-acting');
        const options = document.getElementById('ci-acting-options');
        const emptyNote = document.getElementById('ci-acting-empty');
        const membersEl = document.getElementById('ci-members');
        if (!block || !options || !membersEl || !currentHousehold) return;

        const headId = currentHousehold.head_member_id
            || currentHousehold.members.find((m) => m.is_head)?.id
            || null;

        const ticked = Array.from(
            membersEl.querySelectorAll('input[type="checkbox"]:checked')
        ).map((cb) => Number(cb.value));

        // Head present, or no head on record: nothing to stand in for. Clearing
        // the options also clears any radio that would otherwise still post.
        if (!headId || ticked.includes(Number(headId))) {
            block.hidden = true;
            options.innerHTML = '';
            return;
        }

        // Keep whatever was already chosen if that person is still ticked.
        const previous = options.querySelector('input[type="radio"]:checked')?.value;

        const candidates = currentHousehold.members.filter(
            (m) => ticked.includes(Number(m.id)) && Number(m.id) !== Number(headId)
        );

        options.innerHTML = '';
        candidates.forEach((m) => {
            const label = document.createElement('label');
            label.className = 'radio-row';
            const checked = String(m.id) === String(previous) ? ' checked' : '';
            label.innerHTML =
                '<input type="radio" name="acting_head_member_id" value="' + m.id + '"'
                + checked + '> ' + m.full_name;
            options.appendChild(label);
        });

        if (emptyNote) emptyNote.hidden = candidates.length > 0;
        block.hidden = false;
    }

    /* PHASE 6 ITEM 11. "Edit Family" inside the check-in modal used to do
       `window.location = cfg.editRedirectTemplate` -- a navigation to Evacuee
       Profiling with ?edit={id}. That is the redirection complaint: saving from
       there left the operator on Evacuee Profiling instead of the shelter.

       The evacuee modal is now included on this page too, so this swaps one
       modal for another without leaving. editRedirectTemplate has been removed
       from ShelterConfig; the reload path is published by initEvacueeForm() on
       the shared window.EvacTech object. */
    const editBtn = document.getElementById('ci-edit-family');
    if (!editBtn) {
        console.info('[EvacTech/staff] #ci-edit-family not on this page; nothing to wire.');
    } else {
        editBtn.addEventListener('click', async () => {
            if (!currentHousehold) {
                console.error('[EvacTech/staff] Edit Family clicked with no household selected in the check-in modal.');
                return;
            }
            if (!window.EvacTech?.reloadHouseholdIntoForm) {
                console.error('[EvacTech/staff] the evacuee modal is not on this page (window.EvacueeConfig missing), so Edit Family has nothing to open. Include partials/evacuee-modal and set EvacueeConfig.');
                return;
            }
            closeModal('checkinModal');
            openModal('evacueeModal');
            await window.EvacTech.reloadHouseholdIntoForm(currentHousehold.id);
        });
    }

    // Transfer head chain, triggered from the Edit Family modal (evacuees page) via a
    // "Transfer Head" button there -- this file exposes the two modals globally so that
    // page can call window.EvacTech.openTransferFlow(household).
    window.EvacTech = window.EvacTech || {};
    window.EvacTech.openTransferFlow = function (household) {
        document.getElementById('th-current').textContent = household.members.find((m) => m.is_head)?.full_name || '\u2014';
        const optionsEl = document.getElementById('th-options');
        optionsEl.innerHTML = '';
        household.members.filter((m) => !m.is_head).forEach((m) => {
            const label = document.createElement('label');
            label.className = 'radio-row';
            label.innerHTML = `<input type="radio" name="new_head" value="${m.id}" data-name="${m.full_name}"> ${m.full_name}`;
            optionsEl.appendChild(label);
        });
        openModal('transferHeadModal');

        document.getElementById('th-next').onclick = () => {
            const selected = optionsEl.querySelector('input[name="new_head"]:checked');
            if (!selected) { alert('Select a family member first.'); return; }
            closeModal('transferHeadModal');
            document.getElementById('ct-name').textContent = selected.dataset.name;
            document.getElementById('ct-member-id').value = selected.value;
            document.getElementById('confirmTransferForm').action = cfg.transferUrlTemplate.replace(':id', household.id);
            openModal('confirmTransferModal');
        };
    };

    /* PHASE 6 -- confirm the head transfer WITHOUT navigating.

       #confirmTransferForm used to post normally. A navigation tears down every
       modal on the page, so confirming a transfer from inside Edit Family threw
       the operator back to the list and they had to find the family again.

       Posting it with fetch keeps the page alive, so only the two transfer
       modals close and Edit Family stays exactly where it was.

       The form MUST then be repopulated, not merely left open. The head is
       carried in the form as a hidden `members[0][is_head]`, so a form still
       holding the old head would post it on the next Save and silently undo the
       transfer that was just confirmed. loadHouseholdIntoForm() is the existing,
       tested path that rebuilds the head row and the member rows from the
       server, so it is reused rather than shuffling rows by hand -- moving a row
       between the head and member containers means renaming every input on it,
       which is precisely where `members[0][tags][]` bracket bugs come from.

       KNOWN AND ACCEPTED: rebuilding from the server replaces anything typed
       into the form but not yet saved. Agreed as acceptable rather than adding a
       capture-and-reapply pass. */
    const confirmForm = document.getElementById('confirmTransferForm');
    if (!confirmForm) {
        console.info('[EvacTech/staff] #confirmTransferForm not on this page; head transfer stays a normal form post.');
    } else {
        confirmForm.addEventListener('submit', async (e) => {
            // No action means openTransferFlow never ran, so there is no
            // household to post against. Let the browser do whatever it would
            // have done rather than swallow the submit.
            if (!confirmForm.action) {
                console.error('[EvacTech/staff] confirm transfer submitted with no action set; falling back to a normal post.');
                return;
            }
            e.preventDefault();

            const submitBtn = confirmForm.querySelector('button[type="submit"]');
            if (submitBtn) submitBtn.disabled = true;

            try {
                const res = await fetch(confirmForm.action, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': csrfToken(),
                        // Both headers matter: Accept is what makes Laravel's
                        // expectsJson() true, X-Requested-With is what stops a
                        // validation failure redirecting instead of answering 422.
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: new FormData(confirmForm),
                });
                const payload = await res.json().catch(() => null);

                if (!res.ok) {
                    // 422 is the "type transfer exactly" rule failing. Show it in
                    // place; the operator has not lost anything.
                    const msg = payload?.message || 'The transfer could not be completed.';
                    console.error('[EvacTech/staff] head transfer rejected with status ' + res.status, payload);
                    alert(msg);
                    return;
                }

                closeModal('confirmTransferModal');
                closeModal('transferHeadModal');
                confirmForm.reset();

                const note = document.getElementById('evacueeNote');
                if (note) {
                    note.textContent = payload?.message || 'Family head transferred.';
                    note.hidden = false;
                }

                // Repopulate so the form agrees with the database again.
                if (window.EvacTech?.reloadHouseholdIntoForm && payload?.household_id) {
                    await window.EvacTech.reloadHouseholdIntoForm(payload.household_id);
                } else {
                    console.error('[EvacTech/staff] head transferred but the edit form could not be refreshed; reload before saving.');
                }
            } catch (err) {
                console.error('[EvacTech/staff] head transfer request failed.', err);
                alert('The transfer could not be sent. Check your connection and try again.');
            } finally {
                if (submitBtn) submitBtn.disabled = false;
            }
        });
    }

    if (cfg.autoOpen) {
        openModal('checkinModal');
        // Same one-shot rule as ?edit= and ?open=register: a check-in posted
        // from here must not leave ?open=checkin in the Referer.
        stripQueryParam('open');
    }
}

// ---------------------------------------------------------------------
// Relief Distribution: household search + dynamic item rows
// ---------------------------------------------------------------------
function initReliefModals() {
    if (!window.ReliefConfig) return;
    const cfg = window.ReliefConfig;

    const searchInput = document.getElementById('dist-search');
    const resultsList = document.getElementById('dist-results');
    const distForm = document.getElementById('distributeForm');
    const itemsWrap = document.getElementById('dist-items');
    const addItemBtn = document.getElementById('addItemBtn');
    let itemIndex = 1;
    /* Held so "Request a special item for this family" can carry the loaded
       household across instead of making staff search for it a second time. */
    let currentDistHousehold = null;

    /* PHASE 8 ITEM 2. Lifted out of the debounce callback so the modal-open
       handler below can call it too. cfg.searchUrl now points at
       barangay.relief.recipients, which returns households CHECKED IN AT THIS
       SHELTER -- not the roster-wide list evacuees.search returns -- so an empty
       term is a meaningful default: everyone you could hand relief to. */
    async function runDistSearch(term) {
        try {
            const res = await fetch(`${cfg.searchUrl}?q=${encodeURIComponent(term || '')}`, { headers: { Accept: 'application/json' } });
            if (!res.ok) {
                console.error(`[EvacTech/staff] relief recipient search failed with HTTP ${res.status}.`);
                resultsList.hidden = true;
                return;
            }
            const items = await res.json();
            resultsList.innerHTML = '';
            items.forEach((item) => {
                const li = document.createElement('li');
                li.textContent = `${item.head}${matchedSuffix(item)} \u00B7 ${item.size} members`;
                li.addEventListener('click', () => selectHousehold(item.id));
                resultsList.appendChild(li);
            });
            if (items.length === 0) {
                const none = document.createElement('li');
                none.className = 'search-empty';
                none.textContent = term
                    ? 'No checked-in household matches that name.'
                    : 'No household is checked in at this shelter yet.';
                resultsList.appendChild(none);
            }
            resultsList.hidden = false;
        } catch (err) {
            console.error('[EvacTech/staff] relief recipient search error.', err);
            resultsList.hidden = true;
        }
    }

    let debounce;
    if (searchInput) {
        searchInput.addEventListener('input', () => {
            clearTimeout(debounce);
            debounce = setTimeout(() => runDistSearch(searchInput.value.trim()), 250);
        });
    }

    /* Same reset-then-prefill as the check-in modal: a stale household left in
       the form under a fresh candidate list is worse than either alone. */
    document.getElementById('distributeModal')?.addEventListener('evactech:modal-open', () => {
        if (distForm) distForm.hidden = true;
        currentDistHousehold = null;
        if (searchInput) searchInput.value = '';
        runDistSearch('');
    });

    async function selectHousehold(id) {
        const url = cfg.showUrlTemplate.replace(':id', id);
        const res = await fetch(url, { headers: { Accept: 'application/json' } });
        const data = await res.json();
        resultsList.hidden = true;
        currentDistHousehold = data;

        document.getElementById('dist-household-id').value = data.id;
        document.getElementById('dist-code').textContent = data.code;
        document.getElementById('dist-head').textContent = data.members.find((m) => m.is_head)?.full_name || '\u2014';

        const tags = [...new Set(data.members.flatMap((m) => m.tags.map((t) => t.name)))];
        document.getElementById('dist-tags-note').textContent = tags.length
            ? `Household tags: ${tags.join(', ')} \u2014 consider matching special items below.`
            : 'No special tags on this household.';

        distForm.hidden = false;
    }

    // ---- Distribution item rows ----
    // Previously: `row.innerHTML = itemsWrap.children[0].innerHTML.replace(...)`.
    // Cloning row 0's innerHTML meant every added row was a copy of whatever
    // markup row 0 happened to have, and since row 0 had no Remove control,
    // neither did any of its copies -- an accidental extra item could not be
    // taken back without closing the modal and starting over. Now both the first
    // row and the template carry the control, and removal is delegated so it
    // works for rows that did not exist at page load.
    //
    // Indices deliberately are NOT renumbered after a removal. Laravel validates
    // with `items.*`, and the controller iterates $data['items'], so a sparse
    // items[0], items[2] is handled correctly -- and renumbering live inputs is
    // how a quantity ends up attached to the wrong item.
    const itemTemplate = document.getElementById('distItemTemplate');

    const syncRemoveButtons = () => {
        const rows = itemsWrap.querySelectorAll('[data-item-row]');
        rows.forEach((row) => {
            const btn = row.querySelector('[data-remove-item]');
            if (btn) btn.disabled = rows.length <= 1;
        });
    };

    if (addItemBtn && itemTemplate) {
        addItemBtn.addEventListener('click', () => {
            const html = itemTemplate.innerHTML.replace(/__INDEX__/g, String(itemIndex));
            const holder = document.createElement('div');
            holder.innerHTML = html;
            const row = holder.querySelector('[data-item-row]');
            if (!row) return;
            itemsWrap.appendChild(row);
            itemIndex++;
            syncRemoveButtons();
            row.querySelector('select')?.focus();
        });
    }

    if (itemsWrap) {
        itemsWrap.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-remove-item]');
            if (!btn) return;
            /* Never remove the last row: an empty items list fails validation
               server side with a message that would not explain itself. */
            if (itemsWrap.querySelectorAll('[data-item-row]').length <= 1) return;
            btn.closest('[data-item-row]')?.remove();
            syncRemoveButtons();
        });
        syncRemoveButtons();
    }

    // ---- Request Stock prefill ----
    // Opened either from the toolbar (nothing selected) or from an inventory row
    // (that item preselected). initModals() already opened the modal on click;
    // this only fills it in.
    const restockGoodSelect = document.getElementById('req-good');
    if (restockGoodSelect) {
        document.querySelectorAll('[data-request-good]').forEach((btn) => {
            btn.addEventListener('click', () => {
                restockGoodSelect.value = btn.dataset.requestGood;
                document.getElementById('req-qty')?.focus();
            });
        });
    }

    // ---- Special item request ----
    // Reachable two ways: on its own, searching for the family; or from the
    // Distribute modal, where a household is already loaded and carrying it over
    // saves searching for the same family twice.
    initSpecialRequest(cfg, () => currentDistHousehold);

    if (cfg.autoOpen) openModal('distributeModal');
}

// ---------------------------------------------------------------------
// Special item request (per household)
// ---------------------------------------------------------------------
// A deliberately separate search rather than a refactor of the two existing ones
// in this file. Check-in and Distribute both work; folding three call sites into
// one helper in the same pass that introduces a feature is how a working screen
// breaks. Consolidating all three is noted as follow-up.
function initSpecialRequest(cfg, getCurrentHousehold) {
    const modal = document.getElementById('specialModal');
    if (!modal) return;

    const searchInput = document.getElementById('sp-search');
    const resultsList = document.getElementById('sp-results');
    const form = document.getElementById('specialForm');

    async function fillFrom(household) {
        document.getElementById('sp-household-id').value = household.id;
        document.getElementById('sp-code').textContent = household.code;
        document.getElementById('sp-head').textContent =
            household.members.find((m) => m.is_head)?.full_name || '\u2014';

        /* The tags are why this request usually exists -- a PWD or Pregnant tag
           is what prompts asking for a wheelchair or maternity supplies -- so
           they are shown while the item is being typed. */
        const tags = [...new Set(household.members.flatMap((m) => m.tags.map((t) => t.name)))];
        document.getElementById('sp-tags-note').textContent = tags.length
            ? `Household tags: ${tags.join(', ')}`
            : 'No special tags recorded on this household.';

        if (resultsList) resultsList.hidden = true;
        form.hidden = false;
    }

    async function loadHousehold(id) {
        const res = await fetch(cfg.showUrlTemplate.replace(':id', id), {
            headers: { Accept: 'application/json' },
        });
        if (!res.ok) return;
        await fillFrom(await res.json());
    }

    /* PHASE 8 ITEM 2. Shares cfg with the Distribute modal, so it shares the
       scoping too: since ReliefConfig.searchUrl became relief.recipients, this
       picker now also lists only households checked in at this shelter. That is
       the right set -- a special item is requested FOR a family who is here. */
    async function runSpecialSearch(term) {
        if (!resultsList) return;
        try {
            const res = await fetch(`${cfg.searchUrl}?q=${encodeURIComponent(term || '')}`, {
                headers: { Accept: 'application/json' },
            });
            if (!res.ok) {
                console.error(`[EvacTech/staff] special request household search failed with HTTP ${res.status}.`);
                resultsList.hidden = true;
                return;
            }
            const items = await res.json();
            resultsList.innerHTML = '';
            items.forEach((item) => {
                const li = document.createElement('li');
                li.textContent = `${item.head}${matchedSuffix(item)} \u00B7 ${item.size} members`;
                li.addEventListener('click', () => loadHousehold(item.id));
                resultsList.appendChild(li);
            });
            if (items.length === 0) {
                const none = document.createElement('li');
                none.className = 'search-empty';
                none.textContent = term
                    ? 'No checked-in household matches that name.'
                    : 'No household is checked in at this shelter yet.';
                resultsList.appendChild(none);
            }
            resultsList.hidden = false;
        } catch (err) {
            console.error('[EvacTech/staff] special request household search error.', err);
            resultsList.hidden = true;
        }
    }

    let debounce;
    if (searchInput) {
        searchInput.addEventListener('input', () => {
            clearTimeout(debounce);
            debounce = setTimeout(() => runSpecialSearch(searchInput.value.trim()), 250);
        });
    }

    /* Hand-off from the Distribute modal. Closing it first prevents two stacked
       backdrops, where Escape or a backdrop click dismisses only the top one and
       the modal underneath is left open behind it.

       PHASE 8 ITEM 2. The `modal-open` listener below fires for BOTH entry
       paths, including this one -- so when a household is already carried over
       it has to be filled in AFTER the reset, not before, or the reset wipes it
       out again. Hence the flag: the listener does the clearing, this does the
       filling, and the order is guaranteed because dispatchEvent() inside
       openModal() is synchronous. */
    let carriedOver = null;
    document.getElementById('dist-special-link')?.addEventListener('click', () => {
        carriedOver = getCurrentHousehold();
        closeModal('distributeModal');
        openModal('specialModal');
        if (carriedOver) {
            fillFrom(carriedOver);
        } else {
            searchInput?.focus();
        }
        carriedOver = null;
    });

    document.getElementById('specialModal')?.addEventListener('evactech:modal-open', () => {
        /* The hand-off already knows the family and fills the form itself. Left
           unguarded, runSpecialSearch() would resolve a moment LATER and unhide
           the candidate list back over the top of that filled form -- the fetch
           is async, the fillFrom() call is not, so the list would always win the
           race despite being started first. */
        if (carriedOver) return;

        if (form) form.hidden = true;
        if (searchInput) searchInput.value = '';
        runSpecialSearch('');
    });
}

/* DROP 2 -- reunification panel: show only the selected family's existing
   entries. Everything is already in the DOM; this only toggles visibility.

   Delegated on document, like every other handler in this file, so it works for
   panels rendered after load. Toggles el.hidden rather than a class, because
   design-system.css restates [hidden] { display: none !important } on purpose. */
document.addEventListener('change', (e) => {
    const sel = e.target.closest('[data-reunite-select]');
    if (!sel) return;

    const fragId = sel.dataset.reuniteSelect;
    const list = document.querySelector(`[data-reunite-list="${fragId}"]`);
    if (!list) {
        console.error('[EvacTech/staff] reunification: no checkbox list for fragment ' + fragId);
        return;
    }

    const hint = list.querySelector(`[data-reunite-hint="${fragId}"]`);
    if (hint) hint.hidden = !!sel.value;

    list.querySelectorAll('[data-reunite-group]').forEach((g) => {
        const match = g.dataset.reuniteGroup === sel.value;
        g.hidden = !match;
        // Clear any tick left inside a group the operator has navigated away
        // from, so a hidden checkbox can never post an id for a family that was
        // not chosen.
        if (!match) g.querySelectorAll('input[type="checkbox"]').forEach((c) => { c.checked = false; });
    });
});
