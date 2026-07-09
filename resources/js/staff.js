// EvacTech staff UI behavior. No build step assumptions beyond Vite bundling this file.

document.addEventListener('DOMContentLoaded', () => {
    initTheme();
    initSidebarCollapse();
    initModals();
    initConfirmForms();
    initEvacueeForm();
    initShelterModals();
    initReliefModals();
});

// ---------------------------------------------------------------------
// Theme toggle (server-persisted via cookie, no localStorage)
// ---------------------------------------------------------------------
function initTheme() {
    const toggle = document.getElementById('themeToggle');
    if (!toggle) return;
    toggle.addEventListener('click', () => {
        const html = document.documentElement;
        const next = html.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
        html.setAttribute('data-theme', next);
        document.cookie = `theme=${next};path=/;max-age=31536000`;
    });
}

function initSidebarCollapse() {
    const btn = document.getElementById('collapseToggle');
    const shell = document.querySelector('.staff-shell');
    if (!btn || !shell) return;
    btn.addEventListener('click', () => {
        shell.classList.toggle('sidebar-collapsed');
        btn.textContent = shell.classList.contains('sidebar-collapsed') ? '⟩' : '⟨';
    });
}

// ---------------------------------------------------------------------
// Generic modal open/close (data-open-modal="id" / data-close-modal)
// ---------------------------------------------------------------------
function openModal(id) {
    const el = document.getElementById(id);
    if (el) el.hidden = false;
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

function ageTagLabel(age) {
    if (age === null) return '';
    if (age <= 5) return 'Infant / Young Child';
    if (age >= 60) return 'Senior Citizen';
    return 'Adult';
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
            el.name = `members[${fieldPrefix}][${field === 'tags' ? 'tags[]' : field}]`;
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
        }

        // Presence checkbox only shown in check-in context (Shelter page injects it separately)
        const birthdateInput = row.querySelector('[data-field="birthdate"]');
        const ageTagEl = row.querySelector('[data-age-tag]');
        birthdateInput.addEventListener('change', () => {
            const age = ageFromBirthdate(birthdateInput.value);
            if (age !== null) {
                ageTagEl.hidden = false;
                ageTagEl.textContent = `${ageTagLabel(age)} (${age} yrs)`;
            } else {
                ageTagEl.hidden = true;
            }
        });

        return row;
    }

    function resetForm() {
        form.reset();
        headRow.innerHTML = '';
        memberRows.innerHTML = '';
        memberIndex = 1;
        headRow.appendChild(makeRow(0, true));
        title.textContent = 'Add New Evacuee Profile';
        form.action = window.EvacueeConfig.storeUrl;
        document.getElementById('evacueeFormMethod').value = 'POST';
        const transferBtn = document.getElementById('transferHeadBtn');
        if (transferBtn) transferBtn.hidden = true;
    }

    addBtn.addEventListener('click', () => {
        memberRows.appendChild(makeRow(memberIndex, false));
        memberIndex++;
    });

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
        title.textContent = 'Edit Family Group';
        const url = window.EvacueeConfig.showUrlTemplate.replace(':id', id);
        const res = await fetch(url, { headers: { Accept: 'application/json' } });
        if (!res.ok) return;
        const data = await res.json();

        document.getElementById('ev-address').value = data.address || '';
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
            const tagSelect = row.querySelector('[data-field="tags"]');
            const tagIds = (m.tags || []).map((t) => String(t.id));
            Array.from(tagSelect.options).forEach((opt) => { opt.selected = tagIds.includes(opt.value); });
            const age = ageFromBirthdate(m.birthdate);
            const ageTagEl = row.querySelector('[data-age-tag]');
            if (age !== null) { ageTagEl.hidden = false; ageTagEl.textContent = `${ageTagLabel(age)} (${age} yrs)`; }

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

    // Support deep-link ?edit={id} from Shelter page's "Edit Family Group" action
    const params = new URLSearchParams(window.location.search);
    if (params.get('edit')) {
        openModal('evacueeModal');
        loadHouseholdIntoForm(params.get('edit'));
    }
    if (window.EvacueeConfig.autoOpen) {
        openModal('evacueeModal');
    }
}

// ---------------------------------------------------------------------
// Shelter page: Check-in Family, Select New Family Head, Confirm Transfer
// ---------------------------------------------------------------------
function initShelterModals() {
    if (!window.ShelterConfig) return;
    const cfg = window.ShelterConfig;

    const searchInput = document.getElementById('ci-search');
    const loadBtn = document.getElementById('ci-load');
    const resultsList = document.getElementById('ci-results');
    const checkinForm = document.getElementById('checkinForm');
    let currentHousehold = null;

    async function runSearch(term) {
        if (!term) { resultsList.hidden = true; return; }
        const res = await fetch(`${cfg.searchUrl}?q=${encodeURIComponent(term)}`, { headers: { Accept: 'application/json' } });
        const items = await res.json();
        resultsList.innerHTML = '';
        items.forEach((item) => {
            const li = document.createElement('li');
            li.textContent = `${item.head} · ${item.size} members · ${item.status.replace('_', ' ')}`;
            li.addEventListener('click', () => loadHousehold(item.id));
            resultsList.appendChild(li);
        });
        resultsList.hidden = items.length === 0;
    }

    let debounce;
    if (searchInput) {
        searchInput.addEventListener('input', () => {
            clearTimeout(debounce);
            debounce = setTimeout(() => runSearch(searchInput.value.trim()), 250);
        });
    }
    if (loadBtn) loadBtn.addEventListener('click', () => runSearch(searchInput.value.trim()));

    async function loadHousehold(id) {
        const url = cfg.showUrlTemplate.replace(':id', id);
        const res = await fetch(url, { headers: { Accept: 'application/json' } });
        const data = await res.json();
        currentHousehold = data;
        resultsList.hidden = true;

        document.getElementById('ci-code').textContent = data.code;
        document.getElementById('ci-head').textContent = data.members.find((m) => m.is_head)?.full_name || '—';

        const membersEl = document.getElementById('ci-members');
        membersEl.innerHTML = '';
        data.members.forEach((m) => {
            const label = document.createElement('label');
            label.className = 'checkbox-row';
            label.innerHTML = `<input type="checkbox" name="present[]" value="${m.id}" checked> ${m.full_name}${m.tags.length ? ' — ' + m.tags.map(t => t.name).join(', ') : ''}`;
            membersEl.appendChild(label);
        });

        checkinForm.action = cfg.checkinUrlTemplate.replace(':id', id);
        checkinForm.hidden = false;
    }

    const editBtn = document.getElementById('ci-edit-family');
    if (editBtn) {
        editBtn.addEventListener('click', () => {
            if (currentHousehold) window.location = cfg.editRedirectTemplate.replace(':id', currentHousehold.id);
        });
    }

    // Transfer head chain, triggered from Edit Family Group screen (evacuees page) via a
    // "Transfer Head" button there — this file exposes the two modals globally so that
    // page can call window.EvacTech.openTransferFlow(household).
    window.EvacTech = window.EvacTech || {};
    window.EvacTech.openTransferFlow = function (household) {
        document.getElementById('th-current').textContent = household.members.find((m) => m.is_head)?.full_name || '—';
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

    if (cfg.autoOpen) openModal('checkinModal');
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

    let debounce;
    if (searchInput) {
        searchInput.addEventListener('input', () => {
            clearTimeout(debounce);
            debounce = setTimeout(async () => {
                const term = searchInput.value.trim();
                if (!term) { resultsList.hidden = true; return; }
                const res = await fetch(`${cfg.searchUrl}?q=${encodeURIComponent(term)}`, { headers: { Accept: 'application/json' } });
                const items = await res.json();
                resultsList.innerHTML = '';
                items.forEach((item) => {
                    const li = document.createElement('li');
                    li.textContent = `${item.head} · ${item.size} members`;
                    li.addEventListener('click', () => selectHousehold(item.id));
                    resultsList.appendChild(li);
                });
                resultsList.hidden = items.length === 0;
            }, 250);
        });
    }

    async function selectHousehold(id) {
        const url = cfg.showUrlTemplate.replace(':id', id);
        const res = await fetch(url, { headers: { Accept: 'application/json' } });
        const data = await res.json();
        resultsList.hidden = true;

        document.getElementById('dist-household-id').value = data.id;
        document.getElementById('dist-code').textContent = data.code;
        document.getElementById('dist-head').textContent = data.members.find((m) => m.is_head)?.full_name || '—';

        const tags = [...new Set(data.members.flatMap((m) => m.tags.map((t) => t.name)))];
        document.getElementById('dist-tags-note').textContent = tags.length
            ? `Household tags: ${tags.join(', ')} — consider matching special items below.`
            : 'No special tags on this household.';

        distForm.hidden = false;
    }

    if (addItemBtn) {
        addItemBtn.addEventListener('click', () => {
            const row = document.createElement('div');
            row.className = 'dist-item-row';
            row.innerHTML = itemsWrap.children[0].innerHTML.replace(/items\[0\]/g, `items[${itemIndex}]`);
            itemsWrap.appendChild(row);
            itemIndex++;
        });
    }

    if (cfg.autoOpen) openModal('distributeModal');
}
