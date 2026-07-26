// City Admin specific behaviors. Loaded alongside staff.js (which provides the
// generic modal system, theme toggle, confirm forms, and age-tagging helpers).

document.addEventListener('DOMContentLoaded', () => {
    initShelterAdmin();
    initUserAdmin();
    initCityEvacueeForm();
});

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
function ageTagLabelCA(age) {
    if (age === null) return '';
    if (age <= 5) return 'Infant / Young Child';
    if (age >= 60) return 'Senior Citizen';
    return 'Adult';
}

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
function initShelterAdmin() {
    const modal = document.getElementById('addShelterModal');
    if (!modal) return;
    const cfg = window.ShelterAdminConfig || {};

    const form = document.getElementById('shelterForm');
    const title = document.getElementById('addShelterTitle');
    const submit = document.getElementById('shelterSubmit');
    const methodInput = document.getElementById('shelterMethod');
    const statusField = document.getElementById('sh-status-field');
    const barangaySelect = document.getElementById('sh-barangay');

    // Barangay stays EDITABLE on edit now. With many shelters per barangay there
    // is nothing structurally special about a shelter's barangay, and a
    // mis-keyed one previously needed a database fix.
    const roster = initRoster('sh-staff-list', 'sh-staff-search', 'sh-staff-count', 'staffName');

    document.querySelectorAll('[data-open-modal="addShelterModal"]').forEach((btn) => {
        btn.addEventListener('click', () => {
            form.reset();
            form.action = cfg.storeUrl || form.action;
            methodInput.value = 'POST';
            title.textContent = 'Add Evacuation Shelter';
            submit.textContent = 'Add Shelter';
            statusField.hidden = true;
            roster?.clear();
        });
    });

    document.querySelectorAll('[data-edit-shelter]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const d = JSON.parse(btn.dataset.editShelter);
            form.reset();
            form.action = d.update_url;
            methodInput.value = 'PUT';
            title.textContent = 'Edit Evacuation Shelter';
            submit.textContent = 'Save Changes';
            statusField.hidden = false;

            document.getElementById('sh-name').value = d.name;
            document.getElementById('sh-address').value = d.address;
            document.getElementById('sh-capacity').value = d.capacity;
            document.getElementById('sh-lat').value = d.latitude ?? '';
            document.getElementById('sh-lng').value = d.longitude ?? '';
            if (barangaySelect) barangaySelect.value = d.barangay_id ?? '';
            // 'full' no longer exists as a status; overcapacity is derived.
            document.getElementById('sh-status').value = d.status === 'inactive' ? 'inactive' : 'active';

            form.querySelector('[name="has_water_supply"]').checked = !!d.has_water_supply;
            form.querySelector('[name="has_medical_desk"]').checked = !!d.has_medical_desk;
            form.querySelector('[name="has_power"]').checked = !!d.has_power;
            form.querySelector('[name="has_communal_kitchen"]').checked = !!d.has_communal_kitchen;

            roster?.set(d.staff);
            openModal('addShelterModal');
        });
    });
}

// ---------------------------------------------------------------------
// User add/edit
// ---------------------------------------------------------------------
function initUserAdmin() {
    const modal = document.getElementById('userModal');
    if (!modal) return;

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
            openModal('userModal');
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
            el.name = `members[${prefix}][${f === 'tags' ? 'tags[]' : f}]`;
        });
        if (isHead) {
            row.querySelector('[data-remove-row]')?.remove();
            const hidden = document.createElement('input');
            hidden.type = 'hidden'; hidden.name = `members[${prefix}][is_head]`; hidden.value = '1';
            row.appendChild(hidden);
        } else {
            row.querySelector('[data-remove-row]').addEventListener('click', () => row.remove());
        }
        const bd = row.querySelector('[data-field="birthdate"]');
        const tag = row.querySelector('[data-age-tag]');
        bd.addEventListener('change', () => {
            const age = ageFromBirthdateCA(bd.value);
            if (age !== null) { tag.hidden = false; tag.textContent = `${ageTagLabelCA(age)} (${age} yrs)`; }
            else tag.hidden = true;
        });
        return row;
    }

    function reset() {
        headRow.innerHTML = '';
        memberRows.innerHTML = '';
        idx = 1;
        headRow.appendChild(makeRow(0, true));
    }

    addBtn?.addEventListener('click', () => { memberRows.appendChild(makeRow(idx, false)); idx++; });
    document.querySelectorAll('[data-open-modal="cityEvacueeModal"]').forEach((b) => b.addEventListener('click', reset));
    reset();
}
