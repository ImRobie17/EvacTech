import { wireAgeGroup } from './age-tiers';
// City Admin specific behaviors. Loaded alongside staff.js (which provides the
// generic modal system, theme toggle, confirm forms, and age-tagging helpers).
//
// ---------------------------------------------------------------------------
// PHASE 3 ITEM 10 -- FIXED: `openModal is not defined`
//
// Edit Shelter and Edit User both populated their modal correctly and then threw
// ReferenceError on the last line, so nothing ever appeared and three roadmap
// sub-items looked unbuilt.
//
// Cause: openModal() is declared in staff.js as a plain function. Under Vite
// every file here is an ES module with its OWN scope, so it was never a global,
// and calling it by bare name from this file could not resolve. staff.js's own
// [data-open-modal] buttons kept working because they call it from inside that
// same module -- which is exactly why "+ Add Shelter" opened and "Edit" did not.
//
// Fix: a local caOpen() below. Not an export from staff.js: that file drives
// every barangay screen and is not worth touching for this. cityadmin-shelter.js
// already solved the identical problem with its own local cdOpen(), so this is
// the established pattern in this codebase, and both versions report a missing
// modal by id instead of failing silently.
// ---------------------------------------------------------------------------

const CA_TAG = '[EvacTech/cityadmin]';

// Announce that this module loaded. If this line is absent from the console, the
// bundle is stale -- run `npm run build`.
console.info(CA_TAG + ' loaded');

document.addEventListener('DOMContentLoaded', () => {
    initShelterAdmin();
    initUserAdmin();
    initCityEvacueeForm();
});

// Local modal opener. See the header note above for why this is not imported.
function caOpen(id) {
    const el = document.getElementById(id);
    if (!el) {
        console.error(CA_TAG + ' modal #' + id + ' not found in the DOM. Check that the '
            + 'view pushes it into @stack(\'modals\') and that the layout renders that stack.');
        return;
    }
    el.hidden = false;
}

function ageFromBirthdateCA(dateStr) {
    if (!dateStr) return null;
    const dob = new Date(dateStr);
    if (isNaN(dob)) return null;
    const t = new Date();
    let age = t.getFullYear() - dob.getFullYear();
    const m = t.getMonth() - dob.getMonth();
    if (m < 0 || (m === 0 && t.getDate() < dob.getDate())) age--;
    return age;
}
// PHASE 4 item 15b: ageTagLabelCA() deleted here. It returned the two
// classifications Phase 2 retired (Senior Citizen, Infant / Young Child), which
// are age tiers now, not tickable categories -- and it was called from nowhere.
// Age labels come from AgeTier via ./age-tiers.

// ---------------------------------------------------------------------
// Reusable roster picker (checkbox list + filter + live count)
// ---------------------------------------------------------------------
// Used by both the shelter staff roster and the user shelter assignment. Flat
// list, no lead radio and no shift select: every assignment carries identical,
// always-on rights.
function initRoster(listId, searchId, countId, nameAttr) {
    const list = document.getElementById(listId);
    if (!list) return null;

    const search = document.getElementById(searchId);
    const count = document.getElementById(countId);
    const boxes = () => Array.from(list.querySelectorAll('input[type="checkbox"]'));

    function refreshCount() {
        if (!count) return;
        const n = boxes().filter((b) => b.checked).length;
        count.textContent = `${n} selected`;
        count.classList.toggle('roster-count-empty', n === 0);
    }

    list.addEventListener('change', refreshCount);

    search?.addEventListener('input', () => {
        const term = search.value.trim().toLowerCase();
        list.querySelectorAll('.roster-row').forEach((row) => {
            const hay = row.dataset[nameAttr] || '';
            row.hidden = term.length > 0 && !hay.includes(term);
        });
    });

    return {
        clear() {
            boxes().forEach((b) => { b.checked = false; });
            if (search) search.value = '';
            list.querySelectorAll('.roster-row').forEach((r) => { r.hidden = false; });
            refreshCount();
        },
        set(ids) {
            const wanted = (ids || []).map(String);
            boxes().forEach((b) => { b.checked = wanted.includes(String(b.value)); });
            refreshCount();
        },
        refreshCount,
    };
}

// ---------------------------------------------------------------------
// Shelter add/edit
// ---------------------------------------------------------------------
// The staff roster picker is created once, at DOMContentLoaded, because it binds
// a change listener to the list element itself. The BUTTON handlers below are
// delegated on `document` instead, per the project convention: nothing depends on
// script load order, rows added later still work, and there is no
// `if (!config) return` that can kill every feature at once in silence.
let shelterRoster = null;

function initShelterAdmin() {
    // Barangay stays EDITABLE on edit. With many shelters per barangay there is
    // nothing structurally special about a shelter's barangay, and a mis-keyed
    // one previously needed a database fix.
    shelterRoster = initRoster('sh-staff-list', 'sh-staff-search', 'sh-staff-count', 'staffName');
}

// Tell resources/js/shelter-picker.js which shelter the form is now editing and
// where its pin belongs. ONE event, dispatched after the modal is open and the
// fields are filled.
//
// This is the whole contract between the form and the map. The picker is a
// separate Vite entry, so if it instead listened for the same clicks this file
// listens for, which module's handler ran first would depend on <script>
// execution order across two entries -- and the picker would sometimes read
// coordinates this file had not written yet.
function announceShelterForm(detail) {
    document.dispatchEvent(new CustomEvent('evactech:shelter-form-open', { detail }));
}

// ---- Add ----
// staff.js's initModals() also unhides this modal, because the button carries
// data-open-modal. This handler opens it AGAIN anyway, and that is deliberate:
// setting hidden = false twice costs nothing, whereas depending on another
// module's handler having already run would make the map's invalidateSize()
// silently conditional on <script> ordering. Implicit coupling of exactly that
// kind is what produced the two earlier debugging rounds.
document.addEventListener('click', (e) => {
    if (!e.target.closest('[data-open-modal="addShelterModal"]')) return;

    const form = document.getElementById('shelterForm');
    if (!form) {
        console.error(CA_TAG + ' #shelterForm not found; Add Shelter cannot be prepared.');
        return;
    }
    const cfg = window.ShelterAdminConfig || {};

    form.reset();
    form.action = cfg.storeUrl || form.action;
    document.getElementById('shelterMethod').value = 'POST';
    document.getElementById('addShelterTitle').textContent = 'Add Evacuation Shelter';
    document.getElementById('shelterSubmit').textContent = 'Add Shelter';
    document.getElementById('sh-status-field').hidden = true;
    shelterRoster?.clear();

    caOpen('addShelterModal');

    announceShelterForm({
        mode: 'add',
        id: null,
        latitude: null,
        longitude: null,
        barangayId: null,
    });
});

// ---- Edit ----
document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-edit-shelter]');
    if (!btn) return;

    let d;
    try {
        d = JSON.parse(btn.dataset.editShelter);
    } catch (err) {
        console.error(CA_TAG + ' the data-edit-shelter payload is not valid JSON.', err);
        return;
    }

    const form = document.getElementById('shelterForm');
    if (!form) {
        console.error(CA_TAG + ' #shelterForm not found; Edit Shelter cannot open.');
        return;
    }

    form.reset();
    form.action = d.update_url;
    document.getElementById('shelterMethod').value = 'PUT';
    document.getElementById('addShelterTitle').textContent = 'Edit Evacuation Shelter';
    document.getElementById('shelterSubmit').textContent = 'Save Changes';
    document.getElementById('sh-status-field').hidden = false;

    document.getElementById('sh-name').value = d.name;
    document.getElementById('sh-address').value = d.address;
    document.getElementById('sh-capacity').value = d.capacity;
    document.getElementById('sh-lat').value = d.latitude ?? '';
    document.getElementById('sh-lng').value = d.longitude ?? '';

    const barangaySelect = document.getElementById('sh-barangay');
    if (barangaySelect) barangaySelect.value = d.barangay_id ?? '';

    // 'full' no longer exists as a status; overcapacity is derived.
    document.getElementById('sh-status').value = d.status === 'inactive' ? 'inactive' : 'active';

    form.querySelector('[name="has_water_supply"]').checked = !!d.has_water_supply;
    form.querySelector('[name="has_medical_desk"]').checked = !!d.has_medical_desk;
    form.querySelector('[name="has_power"]').checked = !!d.has_power;
    form.querySelector('[name="has_communal_kitchen"]').checked = !!d.has_communal_kitchen;

    shelterRoster?.set(d.staff);

    // Open FIRST, then announce: the picker calls invalidateSize(), and Leaflet
    // measures a hidden container as zero and paints a grey box.
    caOpen('addShelterModal');

    announceShelterForm({
        mode: 'edit',
        id: d.id,
        latitude: d.latitude,
        longitude: d.longitude,
        barangayId: d.barangay_id,
    });
});

// ---------------------------------------------------------------------
// User add/edit
// ---------------------------------------------------------------------
function initUserAdmin() {
    const modal = document.getElementById('userModal');
    if (!modal) return;

    // CHAT C -- cross-role guard.
    //
    // app.js imports this file on EVERY page, and the Super Admin users page
    // uses the same element ids (userModal, userForm, userMethod, u-name,
    // u-status-field, u-pw-hint, [data-edit-user]). The `if (!modal) return`
    // above therefore did NOT stop this function running there: it bound its
    // handlers alongside that page's own, and since a bundled module executes
    // after an inline script, these ran last and won. The visible symptom was
    // Super Admin's "+ Add User" opening a dialog headed "Add Barangay
    // Personnel".
    //
    // Super Admin behaviour now lives in resources/js/superadmin.js, a separate
    // Vite entry that only layouts/superadmin loads, so the two no longer meet.
    // This check is the second, independent line of defence.
    if (modal.dataset.userForm !== 'city') return;

    const form = document.getElementById('userForm');
    const title = document.getElementById('userModalTitle');
    const submit = document.getElementById('userSubmit');
    const methodInput = document.getElementById('userMethod');
    const statusField = document.getElementById('u-status-field');
    const pwHint = document.getElementById('u-pw-hint');
    const pw = document.getElementById('u-password');
    const storeUrl = form.getAttribute('action');

    // Shelter assignment replaces the old single "Assigned barangay" select.
    // Editing this roster is the reassignment path: unticking revokes access.
    const roster = initRoster('u-shelter-list', 'u-shelter-search', 'u-shelter-count', 'shelterName');

    document.querySelectorAll('[data-open-modal="userModal"]').forEach((btn) => {
        btn.addEventListener('click', () => {
            form.reset();
            form.action = storeUrl;
            methodInput.value = 'POST';
            title.textContent = 'Add Barangay Personnel';
            submit.textContent = 'Create Account';
            statusField.hidden = true;
            pwHint.textContent = '(min 8 characters)';
            pw.required = true;
            roster?.clear();
        });
    });

    document.querySelectorAll('[data-edit-user]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const d = JSON.parse(btn.dataset.editUser);
            form.reset();
            form.action = d.update_url;
            methodInput.value = 'PUT';
            title.textContent = 'Edit Barangay Personnel';
            submit.textContent = 'Save Changes';
            statusField.hidden = false;
            pwHint.textContent = '(leave blank to keep current)';
            pw.required = false;

            document.getElementById('u-name').value = d.name;
            document.getElementById('u-email').value = d.email;
            document.getElementById('u-contact').value = d.contact_number || '';
            document.getElementById('u-status').value = d.status;
            roster?.set(d.shelters);
            // Was `openModal(...)`, which threw ReferenceError -- see the header
            // note. This is why Edit User appeared to do nothing.
            caOpen('userModal');
        });
    });
}

// ---------------------------------------------------------------------
// City-wide evacuee registration (reuses row pattern, adds shelter selector)
// ---------------------------------------------------------------------
function initCityEvacueeForm() {
    const modal = document.getElementById('cityEvacueeModal');
    if (!modal) return;

    const template = document.getElementById('ceMemberRowTemplate');
    const headRow = document.getElementById('ceHeadRow');
    const memberRows = document.getElementById('ceMemberRows');
    const addBtn = document.getElementById('ceAddMemberBtn');
    let idx = 1;

    function makeRow(prefix, isHead) {
        const node = template.content.cloneNode(true);
        const row = node.querySelector('[data-row]');
        row.querySelectorAll('[data-field]').forEach((el) => {
            const f = el.dataset.field;
            // PHASE 2 BUG FIX: was `members[0][tags[]]`, which PHP parsed as the
            // string key 'tags[' -- so no ticked classification ever reached the
            // server. Correct form is `members[0][tags][]`.
            el.name = `members[${prefix}][${f}]` + (f === 'tags' ? '[]' : '');
        });
        if (isHead) {
            row.querySelector('[data-remove-row]')?.remove();
            const hidden = document.createElement('input');
            hidden.type = 'hidden'; hidden.name = `members[${prefix}][is_head]`; hidden.value = '1';
            row.appendChild(hidden);
        } else {
            row.querySelector('[data-remove-row]').addEventListener('click', () => row.remove());

            // PHASE 3 ITEM 11b. The template carries required= because the same
            // template is cloned for the head row, where a surname is mandatory.
            // The server rule for a MEMBER surname is nullable -- a blank one is
            // filled from the head's inside HouseholdMemberSync -- so leaving the
            // attribute on lets the browser block the submit before the relaxed
            // rule is ever reached.
            row.querySelector('[data-field="last_name"]')?.removeAttribute('required');
        }
        // Birthdate wins and locks the age-group dropdown; no birthdate leaves
        // it open as the fast tag-first path.
        wireAgeGroup(row);
        return row;
    }

    function reset() {
        headRow.innerHTML = '';
        memberRows.innerHTML = '';
        idx = 1;
        headRow.appendChild(makeRow(0, true));
    }

    /* PHASE 3 ITEM 11b -- surname inheritance, client side.

       Item 9 relaxed the server rule and taught HouseholdMemberSync to fill a
       blank member surname from the head's; that is the guarantee. This is the
       affordance the City Admin forms never got: the operator SEES the
       inherited name and can type over it, so a mixed-surname family is
       corrected before saving rather than discovered afterwards. */
    function headLastNameInput() {
        return headRow.querySelector('[data-field="last_name"]');
    }

    /* Fill only the blanks, so correcting one child's surname and then fixing a
       typo in the head's does not silently undo the correction. */
    function fillBlankSurnames() {
        const head = headLastNameInput();
        if (!head) return;
        const surname = head.value.trim();
        if (surname === '') return;

        memberRows.querySelectorAll('[data-field="last_name"]').forEach((input) => {
            if (input.value.trim() === '') input.value = surname;
        });
    }

    addBtn?.addEventListener('click', () => {
        const row = makeRow(idx, false);
        const head = headLastNameInput();
        const surname = head ? head.value.trim() : '';
        if (surname !== '') {
            const field = row.querySelector('[data-field="last_name"]');
            if (field) field.value = surname;
        }
        memberRows.appendChild(row);
        idx++;
    });

    /* Delegated on the modal, not bound to the inputs: reset() replaces
       headRow's contents wholesale, so a listener attached to the input itself
       would be thrown away with it. Capture phase, because blur does not
       bubble. */
    modal.addEventListener('blur', (e) => {
        if (typeof e.target.matches !== 'function') return;
        if (!e.target.matches('[data-field="last_name"]')) return;

        if (headRow.contains(e.target)) {
            fillBlankSurnames();
            return;
        }

        if (!memberRows.contains(e.target)) return;
        if (e.target.value.trim() !== '') return;

        const head = headLastNameInput();
        const surname = head ? head.value.trim() : '';
        if (surname !== '') e.target.value = surname;
    }, true);

    document.querySelectorAll('[data-open-modal="cityEvacueeModal"]').forEach((b) => b.addEventListener('click', reset));
    reset();
}
