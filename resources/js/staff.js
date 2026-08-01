import { wireAgeGroup, tierShortLabel } from './age-tiers';
// PHASE 4 item B.4: initTheme() moved to its own module so the public bundle
// can have it without pulling in this file. Behaviour is unchanged.
import { initTheme } from './theme';
// EvacTech staff UI behavior. No build step assumptions beyond Vite bundling this file.

document.addEventListener('DOMContentLoaded', () => {
    initTheme();
    initSidebarCollapse();
    initMobileNav();
    initShelterSwitcher();
    initConnectivity();
    initModals();
    initConfirmForms();
    initEvacueeForm();
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
// Active shelter switcher
// ---------------------------------------------------------------------
// One barangay can hold many shelters and a staff member can be rostered to
// several, so the header states which shelter every screen is acting on.
// Changing the dropdown submits immediately -- an extra "Switch" click is one
// more thing to forget mid-emergency. The button stays for keyboard/no-JS use.
function initShelterSwitcher() {
    const form = document.getElementById('shelterSwitchForm');
    const select = document.getElementById('activeShelter');
    if (!form || !select) return;

    let current = select.value;
    select.addEventListener('change', () => {
        if (select.value === current) return;
        current = select.value;
        form.submit();
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
        headRow.innerHTML = '';
        memberRows.innerHTML = '';
        memberIndex = 1;
        headRow.appendChild(makeRow(0, true));
        title.textContent = 'Add New Evacuee Profile';
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
        title.textContent = 'Edit Family Group';
        const url = window.EvacueeConfig.showUrlTemplate.replace(':id', id);
        const res = await fetch(url, { headers: { Accept: 'application/json' } });
        if (!res.ok) return;
        const data = await res.json();

        document.getElementById('ev-address').value = data.address || '';
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
            li.textContent = `${item.head} \u00B7 ${item.size} members \u00B7 ${item.status.replace('_', ' ')}`;
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
    }

    const editBtn = document.getElementById('ci-edit-family');
    if (editBtn) {
        editBtn.addEventListener('click', () => {
            if (currentHousehold) window.location = cfg.editRedirectTemplate.replace(':id', currentHousehold.id);
        });
    }

    // Transfer head chain, triggered from Edit Family Group screen (evacuees page) via a
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
    /* Held so "Request a special item for this family" can carry the loaded
       household across instead of making staff search for it a second time. */
    let currentDistHousehold = null;

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
                    li.textContent = `${item.head} \u00B7 ${item.size} members`;
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

    let debounce;
    if (searchInput) {
        searchInput.addEventListener('input', () => {
            clearTimeout(debounce);
            debounce = setTimeout(async () => {
                const term = searchInput.value.trim();
                if (!term) { resultsList.hidden = true; return; }
                const res = await fetch(`${cfg.searchUrl}?q=${encodeURIComponent(term)}`, {
                    headers: { Accept: 'application/json' },
                });
                const items = await res.json();
                resultsList.innerHTML = '';
                items.forEach((item) => {
                    const li = document.createElement('li');
                    li.textContent = `${item.head} \u00B7 ${item.size} members`;
                    li.addEventListener('click', () => loadHousehold(item.id));
                    resultsList.appendChild(li);
                });
                resultsList.hidden = items.length === 0;
            }, 250);
        });
    }

    /* Hand-off from the Distribute modal. Closing it first prevents two stacked
       backdrops, where Escape or a backdrop click dismisses only the top one and
       the modal underneath is left open behind it. */
    document.getElementById('dist-special-link')?.addEventListener('click', () => {
        const household = getCurrentHousehold();
        closeModal('distributeModal');
        openModal('specialModal');
        if (household) {
            fillFrom(household);
        } else {
            searchInput?.focus();
        }
    });
}
