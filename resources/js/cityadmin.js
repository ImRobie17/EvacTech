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
// Shelter add/edit
// ---------------------------------------------------------------------
function initShelterAdmin() {
    const modal = document.getElementById('addShelterModal');
    if (!modal || !window.ShelterAdminConfig) return;
    const cfg = window.ShelterAdminConfig;

    const form = document.getElementById('shelterForm');
    const title = document.getElementById('addShelterTitle');
    const submit = document.getElementById('shelterSubmit');
    const methodInput = document.getElementById('shelterMethod');
    const barangayField = document.getElementById('sh-barangay-field');
    const statusField = document.getElementById('sh-status-field');
    const barangaySelect = document.getElementById('sh-barangay');
    const managerSelect = document.getElementById('sh-manager');

    async function loadManagers(barangayId, selected = '') {
        managerSelect.innerHTML = '<option value="">— None —</option>';
        if (!barangayId) return;
        try {
            const res = await fetch(cfg.managersUrlTemplate.replace(':id', barangayId), { headers: { Accept: 'application/json' } });
            const users = await res.json();
            users.forEach((u) => {
                const opt = document.createElement('option');
                opt.value = u.id; opt.textContent = u.name;
                if (String(u.id) === String(selected)) opt.selected = true;
                managerSelect.appendChild(opt);
            });
        } catch (e) { /* leave as None */ }
    }

    barangaySelect?.addEventListener('change', () => loadManagers(barangaySelect.value));

    // Create mode (default)
    document.querySelectorAll('[data-open-modal="addShelterModal"]').forEach((btn) => {
        btn.addEventListener('click', () => {
            form.reset();
            form.action = cfg.storeUrl;
            methodInput.value = 'POST';
            title.textContent = 'Add Evacuation Shelter';
            submit.textContent = 'Add Shelter';
            barangayField.hidden = false;
            statusField.hidden = true;
            managerSelect.innerHTML = '<option value="">— None —</option>';
        });
    });

    // Edit mode
    document.querySelectorAll('[data-edit-shelter]').forEach((btn) => {
        btn.addEventListener('click', async () => {
            const d = JSON.parse(btn.dataset.editShelter);
            form.reset();
            form.action = d.update_url;
            methodInput.value = 'PUT';
            title.textContent = 'Edit Evacuation Shelter';
            submit.textContent = 'Save Changes';
            barangayField.hidden = true;   // barangay is fixed after creation
            statusField.hidden = false;

            document.getElementById('sh-name').value = d.name;
            document.getElementById('sh-address').value = d.address;
            document.getElementById('sh-capacity').value = d.capacity;
            document.getElementById('sh-status').value = d.status;
            form.querySelector('[name="has_water_supply"]').checked = !!d.has_water_supply;
            form.querySelector('[name="has_medical_desk"]').checked = !!d.has_medical_desk;
            form.querySelector('[name="has_power"]').checked = !!d.has_power;
            form.querySelector('[name="has_communal_kitchen"]').checked = !!d.has_communal_kitchen;

            openModal('addShelterModal');
            // manager list needs the barangay; in edit we don't change barangay,
            // so fetch by the shelter's existing barangay via a data attribute if present.
            if (d.barangay_id) await loadManagers(d.barangay_id, d.managed_by);
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
            document.getElementById('u-barangay').value = d.barangay_id || '';
            document.getElementById('u-status').value = d.status;
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
