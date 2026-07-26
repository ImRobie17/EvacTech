// City Admin shelter detail page behaviour.
//
// Deliberately separate from staff.js: that file drives the barangay screens, and
// sharing it is what let role-specific route logic leak into shared code.
//
// ---------------------------------------------------------------------------
// HARDENING NOTE (v2)
//
// v1 wired click handlers inside a DOMContentLoaded callback, guarded by
// `if (!window.CityShelterConfig) return;`. That had two silent-failure modes:
// a missing config object killed all three features at once with no message, and
// handlers bound at load time could not see rows added later.
//
// v2 uses EVENT DELEGATION on `document`, so nothing depends on script load
// order or on when elements appear, and it reports problems loudly to the
// console instead of doing nothing. If you ever see "nothing happens" again,
// the console will say why.
// ---------------------------------------------------------------------------

const TAG = '[EvacTech/city-shelter]';

function cfg() {
    const c = window.CityShelterConfig;
    if (!c) {
        console.error(
            TAG + ' window.CityShelterConfig is missing. The inline config block in ' +
            'cityadmin/shelters/show.blade.php did not run. Check that the page ' +
            'extends layouts.cityadmin and that the layout renders @stack(\'scripts\').'
        );
        return null;
    }
    return c;
}

// Announce that this module loaded at all. If this line is absent from the
// console, the file is not in the built bundle -- check that resources/js/app.js
// imports it and re-run `npm run build`.
console.info(TAG + ' loaded');

// ---------------------------------------------------------------------
// Small helpers
// ---------------------------------------------------------------------
function cdAge(dateStr) {
    if (!dateStr) return null;
    const dob = new Date(dateStr);
    if (isNaN(dob)) return null;
    const t = new Date();
    let age = t.getFullYear() - dob.getFullYear();
    const m = t.getMonth() - dob.getMonth();
    if (m < 0 || (m === 0 && t.getDate() < dob.getDate())) age--;
    return age;
}

function cdAgeLabel(age) {
    if (age === null) return '';
    if (age <= 5) return 'Infant / Young Child';
    if (age >= 60) return 'Senior Citizen';
    return 'Adult';
}

function cdOpen(id) {
    const el = document.getElementById(id);
    if (!el) {
        console.error(TAG + ' modal #' + id + ' not found in the DOM.');
        return;
    }
    el.hidden = false;
}

async function cdJson(url) {
    const res = await fetch(url, {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    });
    if (!res.ok) {
        throw new Error('HTTP ' + res.status + ' for ' + url);
    }
    return res.json();
}

// ---------------------------------------------------------------------
// Type-ahead search, delegated
// ---------------------------------------------------------------------
// Debounced at 250ms: long enough not to hammer the endpoint on every keystroke,
// short enough to feel immediate. Minimum 2 characters.
const searchTimers = {};

function wireSearch(inputId, listId, urlKey, render) {
    document.addEventListener('input', (e) => {
        const input = e.target.closest('#' + inputId);
        if (!input) return;

        const list = document.getElementById(listId);
        const c = cfg();
        if (!list || !c) return;

        clearTimeout(searchTimers[inputId]);
        const term = input.value.trim();

        if (term.length < 2) {
            list.hidden = true;
            return;
        }

        searchTimers[inputId] = setTimeout(async () => {
            list.innerHTML = '';
            const li = document.createElement('li');
            li.className = 'search-empty';
            li.textContent = 'Searching...';
            list.appendChild(li);
            list.hidden = false;

            try {
                const rows = await cdJson(c[urlKey] + '?q=' + encodeURIComponent(term));
                list.innerHTML = '';
                if (!rows.length) {
                    const none = document.createElement('li');
                    none.className = 'search-empty';
                    none.textContent = 'No matching household found.';
                    list.appendChild(none);
                    return;
                }
                rows.forEach((row) => list.appendChild(render(row)));
            } catch (err) {
                console.error(TAG + ' search failed:', err);
                list.innerHTML = '';
                const fail = document.createElement('li');
                fail.className = 'search-empty';
                fail.textContent = 'Search failed. See the browser console.';
                list.appendChild(fail);
            }
        }, 250);
    });
}

function pickable(li, onPick) {
    li.tabIndex = 0;
    li.addEventListener('click', onPick);
    li.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            onPick();
        }
    });
    return li;
}

// ---------------------------------------------------------------------
// Check-in Family
// ---------------------------------------------------------------------
wireSearch('cd-ci-search', 'cd-ci-results', 'checkinSearchUrl', (row) => {
    const li = document.createElement('li');
    const bits = [row.head, row.size + ' members'];
    if (row.origin_barangay) bits.push('Brgy. ' + row.origin_barangay);
    // Surface where they currently are: checking in someone who is checked in
    // elsewhere is a legitimate transfer, not an error.
    if (row.status === 'checked_in' && row.current_center) {
        bits.push('currently at ' + row.current_center);
    } else {
        bits.push(String(row.status || '').replace('_', ' '));
    }
    li.textContent = bits.join(' \u00B7 ');
    return pickable(li, () => loadForCheckin(row.id));
});

async function loadForCheckin(id) {
    const c = cfg();
    if (!c) return;

    const form = document.getElementById('cdCheckinForm');
    const results = document.getElementById('cd-ci-results');
    if (!form) {
        console.error(TAG + ' #cdCheckinForm not found.');
        return;
    }

    let data;
    try {
        data = await cdJson(c.householdUrlTemplate.replace(':id', id));
    } catch (err) {
        console.error(TAG + ' could not load household ' + id + ':', err);
        return;
    }

    form.action = c.checkinUrlTemplate.replace(':id', id);
    document.getElementById('cd-ci-code').textContent = data.code;
    document.getElementById('cd-ci-head').textContent =
        data.members.find((m) => m.is_head)?.full_name || '\u2014';
    document.getElementById('cd-ci-origin').textContent =
        data.origin_barangay ? 'From Brgy. ' + data.origin_barangay : '';

    // Everyone ticked by default: the common case is a whole family arriving
    // together, and unticking one person is faster than ticking six.
    const box = document.getElementById('cd-ci-members');
    box.innerHTML = '';
    data.members.forEach((m) => {
        const label = document.createElement('label');
        label.className = 'checkbox-row';
        const tags = (m.tags || []).map((t) => t.name).join(', ');
        label.innerHTML =
            '<input type="checkbox" name="present[]" value="' + m.id + '" checked> ' +
            m.full_name + (tags ? ' \u2014 ' + tags : '');
        box.appendChild(label);
    });

    if (results) results.hidden = true;
    form.hidden = false;
}

// Reset the check-in modal each time it opens.
document.addEventListener('click', (e) => {
    if (!e.target.closest('[data-open-modal="cdCheckinModal"]')) return;
    const search = document.getElementById('cd-ci-search');
    const results = document.getElementById('cd-ci-results');
    const form = document.getElementById('cdCheckinForm');
    if (search) search.value = '';
    if (results) results.hidden = true;
    if (form) form.hidden = true;
});

// ---------------------------------------------------------------------
// Edit Family Group -- opens IN PLACE on this page
// ---------------------------------------------------------------------
let editIndex = 1; // 0 reserved for the household head

function makeMemberRow(prefix, isHead) {
    const template = document.getElementById('cdMemberRowTemplate');
    if (!template) {
        console.error(TAG + ' #cdMemberRowTemplate not found.');
        return null;
    }

    const row = template.content.cloneNode(true).querySelector('[data-row]');

    row.querySelectorAll('[data-field]').forEach((el) => {
        const f = el.dataset.field;
        el.name = 'members[' + prefix + '][' + (f === 'tags' ? 'tags[]' : f) + ']';
    });

    if (isHead) {
        row.querySelector('[data-remove-row]')?.remove();
        const hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'members[' + prefix + '][is_head]';
        hidden.value = '1';
        row.appendChild(hidden);
    } else {
        row.querySelector('[data-remove-row]')?.addEventListener('click', () => row.remove());
    }

    const bd = row.querySelector('[data-field="birthdate"]');
    const tag = row.querySelector('[data-age-tag]');
    bd?.addEventListener('change', () => {
        const age = cdAge(bd.value);
        if (age === null) {
            tag.hidden = true;
            return;
        }
        tag.hidden = false;
        tag.textContent = cdAgeLabel(age) + ' (' + age + ' yrs)';
    });

    return row;
}

function fillMemberRow(row, m) {
    row.querySelector('[data-field="id"]').value = m.id;
    // The API splits the stored "Last, First Middle" server-side rather than the
    // browser guessing at comma positions -- that guessing was the cause of the
    // unreliable prefill.
    row.querySelector('[data-field="last_name"]').value = m.last_name || '';
    row.querySelector('[data-field="first_name"]').value = m.first_name || '';
    row.querySelector('[data-field="birthdate"]').value = m.birthdate || '';
    row.querySelector('[data-field="sex"]').value = m.sex || '';

    const tagSelect = row.querySelector('[data-field="tags"]');
    const ids = (m.tags || []).map((t) => String(t.id));
    Array.from(tagSelect.options).forEach((o) => { o.selected = ids.includes(o.value); });

    const age = cdAge(m.birthdate);
    const tag = row.querySelector('[data-age-tag]');
    if (age !== null) {
        tag.hidden = false;
        tag.textContent = cdAgeLabel(age) + ' (' + age + ' yrs)';
    }
}

// Delegated: works no matter when the table rows were rendered.
document.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-cd-edit-household]');
    if (!btn) return;
    e.preventDefault();

    const c = cfg();
    if (!c) return;

    const id = btn.dataset.cdEditHousehold;
    const form = document.getElementById('cdEditForm');
    const headBox = document.getElementById('cd-ed-head');
    const memberBox = document.getElementById('cd-ed-members');

    if (!form || !headBox || !memberBox) {
        console.error(TAG + ' edit modal markup missing (#cdEditForm / #cd-ed-head / #cd-ed-members).');
        return;
    }

    let data;
    try {
        data = await cdJson(c.householdUrlTemplate.replace(':id', id));
    } catch (err) {
        console.error(TAG + ' could not load household ' + id + ':', err);
        return;
    }

    form.reset();
    form.action = c.updateUrlTemplate.replace(':id', id);
    headBox.innerHTML = '';
    memberBox.innerHTML = '';
    editIndex = 1;

    document.getElementById('cd-ed-address').value = data.address || '';
    const brgy = document.getElementById('cd-ed-barangay');
    if (brgy) brgy.value = data.origin_barangay_id ? String(data.origin_barangay_id) : '';

    data.members.forEach((m) => {
        const isHead = !!m.is_head;
        const row = makeMemberRow(isHead ? 0 : editIndex, isHead);
        if (!row) return;
        fillMemberRow(row, m);
        if (!isHead) editIndex++;
        (isHead ? headBox : memberBox).appendChild(row);
    });

    // Defensive: a household with no flagged head would render an empty
    // Household Head fieldset and then fail server-side validation.
    if (!headBox.children.length) {
        const row = makeMemberRow(0, true);
        if (row) headBox.appendChild(row);
    }

    cdOpen('cdEditModal');
});

document.addEventListener('click', (e) => {
    if (!e.target.closest('#cdAddMemberBtn')) return;
    const memberBox = document.getElementById('cd-ed-members');
    const row = makeMemberRow(editIndex, false);
    if (memberBox && row) {
        memberBox.appendChild(row);
        editIndex++;
    }
});

// ---------------------------------------------------------------------
// Distribute Relief
// ---------------------------------------------------------------------
wireSearch('cd-dist-search', 'cd-dist-results', 'reliefSearchUrl', (row) => {
    const li = document.createElement('li');
    li.textContent = row.head + ' \u00B7 ' + row.size + ' present';
    return pickable(li, () => {
        document.getElementById('cd-dist-household-id').value = row.id;
        document.getElementById('cd-dist-code').textContent = row.code;
        document.getElementById('cd-dist-head').textContent = row.head;
        const results = document.getElementById('cd-dist-results');
        const form = document.getElementById('cdDistributeForm');
        if (results) results.hidden = true;
        if (form) form.hidden = false;
    });
});

document.addEventListener('click', (e) => {
    if (!e.target.closest('[data-open-modal="cdDistributeModal"]')) return;
    const search = document.getElementById('cd-dist-search');
    const results = document.getElementById('cd-dist-results');
    const form = document.getElementById('cdDistributeForm');
    if (search) search.value = '';
    if (results) results.hidden = true;
    if (form) form.hidden = true;
});

let distItemIndex = 1;

document.addEventListener('click', (e) => {
    if (!e.target.closest('#cdAddItemBtn')) return;

    const c = cfg();
    const itemBox = document.getElementById('cd-dist-items');
    if (!c || !itemBox) return;

    const row = document.createElement('div');
    row.className = 'dist-item-row';

    const select = document.createElement('select');
    select.name = 'items[' + distItemIndex + '][relief_good_id]';
    select.required = true;
    select.setAttribute('aria-label', 'Relief good');
    const blank = document.createElement('option');
    blank.value = '';
    blank.textContent = 'Select item';
    select.appendChild(blank);
    (c.goodsOptions || []).forEach((g) => {
        const o = document.createElement('option');
        o.value = g.id;
        o.textContent = g.label;
        select.appendChild(o);
    });

    const qty = document.createElement('input');
    qty.type = 'number';
    qty.name = 'items[' + distItemIndex + '][quantity]';
    qty.min = '1';
    qty.value = '1';
    qty.required = true;
    qty.setAttribute('aria-label', 'Quantity');

    const remove = document.createElement('button');
    remove.type = 'button';
    remove.className = 'btn-link btn-link-danger';
    remove.textContent = 'Remove';
    remove.addEventListener('click', () => row.remove());

    row.appendChild(select);
    row.appendChild(qty);
    row.appendChild(remove);
    itemBox.appendChild(row);
    distItemIndex++;
});
