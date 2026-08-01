import { wireAgeGroup } from './age-tiers';
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

// PHASE 4 item 15b: cdAgeLabel() deleted here -- the same retired-classification
// helper as ageTagLabelCA() in cityadmin.js, likewise called from nowhere.

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
        // PHASE 2 BUG FIX: 'members[0][tags[]]' parsed as the string key 'tags['
        // in PHP, silently discarding every ticked classification.
        el.name = 'members[' + prefix + '][' + f + ']' + (f === 'tags' ? '[]' : '');
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

        // PHASE 3 ITEM 11b. The template carries required= because the same
        // template is cloned for the head row, where a surname is mandatory. The
        // server rule for a MEMBER surname is nullable -- a blank one is filled
        // from the head's inside HouseholdMemberSync -- so leaving the attribute
        // on lets the browser block the submit before the relaxed rule is ever
        // reached.
        row.querySelector('[data-field="last_name"]')?.removeAttribute('required');
    }

    // Birthdate wins and locks the age-group dropdown; with no birthdate the
    // dropdown is the operator's fast tag-first path (and is then required).
    wireAgeGroup(row);

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

    // Categories are checkboxes now, not a 1-row-tall multi-select.
    const ids = (m.tags || []).map((t) => String(t.id));
    row.querySelectorAll('[data-field="tags"]').forEach((box) => {
        box.checked = ids.includes(box.value);
    });

    // Restore any manually chosen group, then let wireAgeGroup settle the
    // lock/badge state from the birthdate.
    const groupSelect = row.querySelector('[data-field="age_group"]');
    if (groupSelect) groupSelect.value = m.age_tier_fallback || m.age_tier || '';
    wireAgeGroup(row);
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

/* PHASE 3 ITEM 11b -- surname inheritance, client side.

   Item 9 relaxed the server rule and taught HouseholdMemberSync to fill a blank
   member surname from the head's; that is the guarantee. This is the affordance
   the City Admin screens never got: the operator SEES the inherited name and can
   type over it, so a mixed-surname family is corrected before saving rather than
   discovered afterwards. */
function cdHeadLastNameInput() {
    const headBox = document.getElementById('cd-ed-head');
    return headBox ? headBox.querySelector('[data-field="last_name"]') : null;
}

function cdHeadSurname() {
    const head = cdHeadLastNameInput();
    return head ? head.value.trim() : '';
}

/* Fill only the blanks, so correcting one child's surname and then fixing a typo
   in the head's does not silently undo the correction. */
function cdFillBlankSurnames() {
    const memberBox = document.getElementById('cd-ed-members');
    const surname = cdHeadSurname();
    if (!memberBox || surname === '') return;

    memberBox.querySelectorAll('[data-field="last_name"]').forEach((input) => {
        if (input.value.trim() === '') input.value = surname;
    });
}

document.addEventListener('click', (e) => {
    if (!e.target.closest('#cdAddMemberBtn')) return;
    const memberBox = document.getElementById('cd-ed-members');
    const row = makeMemberRow(editIndex, false);
    if (memberBox && row) {
        const surname = cdHeadSurname();
        if (surname !== '') {
            const field = row.querySelector('[data-field="last_name"]');
            if (field) field.value = surname;
        }
        memberBox.appendChild(row);
        editIndex++;
    }
});

/* Delegated on document, in line with this file's hardening note: the edit modal
   is rebuilt from scratch every time a household is loaded, so a listener bound
   to an input would be thrown away with it. Capture phase, because blur does not
   bubble. The head/member containment checks keep this off every other
   last_name field on the page. */
document.addEventListener('blur', (e) => {
    const field = e.target;
    if (!field || typeof field.matches !== 'function') return;
    if (!field.matches('[data-field="last_name"]')) return;

    const headBox = document.getElementById('cd-ed-head');
    const memberBox = document.getElementById('cd-ed-members');
    if (!headBox || !memberBox) return;

    // Leaving the HEAD's surname: push it down into every blank member row.
    if (headBox.contains(field)) {
        cdFillBlankSurnames();
        return;
    }

    // Leaving a MEMBER's surname: pull the head's down, but only into a field
    // the operator left empty. A deliberately different surname is never
    // overwritten.
    if (!memberBox.contains(field)) return;
    if (field.value.trim() !== '') return;

    const surname = cdHeadSurname();
    if (surname !== '') field.value = surname;
}, true);

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

// ---------------------------------------------------------------------
// Distribution item rows
//
// CHAT C -- the first row could not be removed.
//
// The first row was written in Blade with only a select and a quantity input.
// Rows added here got a Remove button; the Blade one never did, so an item
// picked by mistake could only be undone by closing the modal and starting
// over. On top of that, .dist-item-row is `2fr 1fr auto` above 640px, so the
// first row also rendered with an empty third column beside it.
//
// This now mirrors the fix staff.js already carries on the barangay side: a
// <template> in the view with __INDEX__ substituted here, a Remove control on
// EVERY row including the first, removal delegated so it reaches rows that did
// not exist at page load, and the control disabled while only one row remains
// (an empty items list fails validation server side with a message that would
// not explain itself).
//
// The goodsOptions config entry is no longer read for this: the template is
// rendered by Blade from the same $goods collection, so the option list cannot
// drift out of step with the one in the first row.
//
// Indices are deliberately NOT renumbered after a removal. Laravel validates
// with `items.*` and the controller iterates the array, so a sparse
// items[0], items[2] is handled correctly -- and renumbering live inputs is how
// a quantity ends up attached to the wrong item.
// ---------------------------------------------------------------------

let distItemIndex = 1;

function cdSyncRemoveButtons() {
    const itemBox = document.getElementById('cd-dist-items');
    if (!itemBox) return;
    const rows = itemBox.querySelectorAll('[data-item-row]');
    rows.forEach((row) => {
        const btn = row.querySelector('[data-remove-item]');
        if (btn) btn.disabled = rows.length <= 1;
    });
}

document.addEventListener('click', (e) => {
    if (!e.target.closest('[data-open-modal="cdDistributeModal"]')) return;
    const search = document.getElementById('cd-dist-search');
    const results = document.getElementById('cd-dist-results');
    const form = document.getElementById('cdDistributeForm');
    const itemBox = document.getElementById('cd-dist-items');
    if (search) search.value = '';
    if (results) results.hidden = true;
    if (form) form.hidden = true;

    // Reopening the modal starts a fresh distribution, so drop any extra rows
    // left from the previous one. Previously they persisted, and the next
    // household silently inherited the last household's item list.
    if (itemBox) {
        const rows = itemBox.querySelectorAll('[data-item-row]');
        rows.forEach((row, i) => {
            if (i > 0) row.remove();
        });
    }
    distItemIndex = 1;
    cdSyncRemoveButtons();
});

document.addEventListener('click', (e) => {
    if (!e.target.closest('#cdAddItemBtn')) return;

    const itemBox = document.getElementById('cd-dist-items');
    const template = document.getElementById('cdDistItemTemplate');
    if (!itemBox || !template) {
        console.error(
            TAG + ' the distribution item template is missing. Check that ' +
            'cityadmin/shelters/show.blade.php still renders #cdDistItemTemplate.'
        );
        return;
    }

    const html = template.innerHTML.replace(/__INDEX__/g, String(distItemIndex));
    const holder = document.createElement('div');
    holder.innerHTML = html;
    const row = holder.querySelector('[data-item-row]');
    if (!row) return;

    itemBox.appendChild(row);
    distItemIndex++;
    cdSyncRemoveButtons();
    row.querySelector('select')?.focus();
});

document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-remove-item]');
    if (!btn) return;
    const itemBox = document.getElementById('cd-dist-items');
    if (!itemBox || !itemBox.contains(btn)) return;
    if (itemBox.querySelectorAll('[data-item-row]').length <= 1) return;
    btn.closest('[data-item-row]')?.remove();
    cdSyncRemoveButtons();
});

document.addEventListener('DOMContentLoaded', cdSyncRemoveButtons);
