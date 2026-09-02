import { renderComposition } from './relief-composition.js';
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

/* PHASE 8 ITEM 2. Two changes here, and both are in this one helper because
   City Admin -- unlike the barangay side, which has three copies -- funnels
   every type-ahead through it.

   1. The `term.length < 2` early return is gone. It was the only reason these
      pickers opened blank: the endpoints have always returned their first ten
      rows for an empty term, so the minimum length was refusing to ask a
      question the server was ready to answer. The 250ms debounce and the
      server's own limit(10) are what protect the endpoint; a character count
      never was.

   2. It now RETURNS run(), so the reset-on-open handlers further down can fire
      a blank search themselves. Previously the search could only be triggered
      by an `input` event, which opening a modal does not produce.

   emptyText takes both cases because they are different claims. With a term,
   nothing matched what was typed. Without one, this list IS the complete
   candidate set and "no match" would be misleading -- there is nobody to pick. */
function wireSearch(inputId, listId, urlKey, render, emptyText) {
    const messages = emptyText || {};

    async function run(term) {
        const list = document.getElementById(listId);
        const c = cfg();
        if (!list || !c) return;

        list.innerHTML = '';
        const pending = document.createElement('li');
        pending.className = 'search-empty';
        pending.textContent = 'Searching...';
        list.appendChild(pending);
        list.hidden = false;

        try {
            const rows = await cdJson(c[urlKey] + '?q=' + encodeURIComponent(term || ''));
            list.innerHTML = '';
            if (!rows.length) {
                const none = document.createElement('li');
                none.className = 'search-empty';
                none.textContent = term
                    ? (messages.term || 'No matching household found.')
                    : (messages.blank || 'Nothing available to choose from yet.');
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
    }

    document.addEventListener('input', (e) => {
        const input = e.target.closest('#' + inputId);
        if (!input) return;
        if (!document.getElementById(listId) || !cfg()) return;

        clearTimeout(searchTimers[inputId]);
        const term = input.value.trim();
        searchTimers[inputId] = setTimeout(() => run(term), 250);
    });

    return run;
}

/* PHASE 9 ITEM 1 -- say WHICH member matched, when it was not the head. Same
   rule and same wording as matchedSuffix() in staff.js; the two are separate
   because they are separate ES modules, and the phrasing is short enough that
   duplicating it beats exporting it across a boundary this codebase otherwise
   keeps closed. */
function cdMatchedSuffix(row) {
    return row && row.matched ? ' \u00B7 matched: ' + row.matched : '';
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
/* PHASE 8 ITEM 2. Captured so the reset-on-open handler below can prefill. */
/* PHASE 9 ITEMS 3 + 5 -- one row, three possible destinations.

   The comment this replaces said that checking in someone who is checked in
   elsewhere is "a legitimate transfer, not an error". The intent was right and
   the implementation was the bug: it was a legitimate transfer that produced no
   transfer record, so a family moved between shelters and the transfer log
   showed nothing. Occupancy was always correct at both ends; the history was
   not.

   Now the server labels each row (Household::checkinAction) and the row renders
   the control that case actually needs. 'arrival' and 'transfer' rows are
   BUTTONS carrying data-presence / data-tx-create, which transfers.js already
   delegates on document -- so the click crosses an ES module boundary without
   anyone calling show() or cdOpen() by bare name and throwing ReferenceError. */
const runCheckinSearch = wireSearch('cd-ci-search', 'cd-ci-results', 'checkinSearchUrl', (row) => {
    const li = document.createElement('li');
    const label = row.head + cdMatchedSuffix(row) + ' \u00B7 ' + row.size + ' members'
        + (row.origin_barangay ? ' \u00B7 Brgy. ' + row.origin_barangay : '');

    if (row.action === 'arrival') {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn-link text-left';
        btn.dataset.presence = row.id;
        btn.textContent = label + ' \u00B7 already checked in here, '
            + (Number(row.absent) || 0) + ' not yet arrived \u00B7 Record arrival';
        // data-close-modal is bound over elements that existed at init, so a row
        // built now cannot close the modal by attribute. Close it explicitly.
        btn.addEventListener('click', () => {
            const modal = document.getElementById('cdCheckinModal');
            if (modal) modal.hidden = true;
        });
        li.appendChild(btn);
        return li;
    }

    /* DROP B -- see the matching note in staff.js. Listed with a family
       elsewhere but absent there means a registration, not a transfer.
       Plain text on purpose: it stops the wrong action without wiring a
       shortcut across modals. */
    if (row.action === 'separated') {
        /* DROP D. Left as plain text HERE, unlike the barangay picker, and the
           reason is structural rather than a preference: this screen has no
           register modal at all (cdCheckinModal, cdEditModal, cdDistributeModal
           and cdReceiveModal are the only four), so there is nothing for a
           button to open. Registration lives on Evacuee Profiling, so the row
           says so rather than offering a control that cannot exist. */
        li.className = 'text-sm';
        li.textContent = label + ' \u00B7 listed with a family at '
            + (row.current_center || 'another shelter') + ' but not present there '
            + '\u00B7 Do NOT transfer. Register them as a new household from Evacuee Profiling.';
        return li;
    }

    if (row.action === 'transfer') {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn-link text-left';
        btn.dataset.txCreate = '';
        btn.dataset.householdId = row.id;
        btn.dataset.householdCode = row.code || '';
        btn.dataset.householdHead = row.head || '';
        btn.dataset.householdPresent = row.members_present || 0;
        btn.dataset.centerName = row.current_center || '';
        btn.dataset.centerId = row.current_center_id || '';
        btn.textContent = label + ' \u00B7 checked in at '
            + (row.current_center || 'another shelter') + ' \u00B7 Move by Transfer';
        btn.addEventListener('click', () => {
            const modal = document.getElementById('cdCheckinModal');
            if (modal) modal.hidden = true;
        });
        li.appendChild(btn);
        return li;
    }

    li.textContent = label + ' \u00B7 ' + String(row.status || '').replace('_', ' ');
    return pickable(li, () => loadForCheckin(row.id));
}, {
    term: 'No household matches that name.',
    blank: 'No household is available to check in here. Register one first.',
});

// PHASE 9 ITEM 2. Module-scoped, like currentHousehold in staff.js.
let cdCheckinHousehold = null;

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

    // PHASE 9 ITEM 2. Kept so the stand-in prompt can rebuild itself from the
    // live tick state without refetching the family on every change.
    cdCheckinHousehold = data;
    cdSyncActingHead();
}

/* PHASE 9 ITEM 2 -- the stand-in head prompt.

   Same rule, same wording and same behaviour as initCheckin() in staff.js. The
   two are separate because the two modals live on different pages and each
   module finds its controls by id; the RULE they enforce is shared, and it lives
   on the server in Household::checkinAction() and in the two check-in guards.

   Rebuilt from the live ticks rather than once on load, because the operator
   decides who is present by unticking people and the head is often the last one
   they untick. */
function cdSyncActingHead() {
    const block = document.getElementById('cd-ci-acting');
    const options = document.getElementById('cd-ci-acting-options');
    const emptyNote = document.getElementById('cd-ci-acting-empty');
    const membersEl = document.getElementById('cd-ci-members');
    if (!block || !options || !membersEl || !cdCheckinHousehold) return;

    const headId = cdCheckinHousehold.head_member_id
        || (cdCheckinHousehold.members || []).find((m) => m.is_head)?.id
        || null;

    const ticked = Array.from(
        membersEl.querySelectorAll('input[type="checkbox"]:checked')
    ).map((cb) => Number(cb.value));

    // Head present, or no head on record: nothing to stand in for. Emptying the
    // options also removes any radio that would otherwise still post.
    if (!headId || ticked.includes(Number(headId))) {
        block.hidden = true;
        options.innerHTML = '';
        return;
    }

    const previous = options.querySelector('input[type="radio"]:checked')?.value;

    const candidates = (cdCheckinHousehold.members || []).filter(
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

/* Delegated on document: the tick boxes are built by loadForCheckin() and did
   not exist when this module ran. Gotcha 21. */
document.addEventListener('change', (e) => {
    if (!e.target.closest('#cd-ci-members')) return;
    cdSyncActingHead();
});

// Reset the check-in modal each time it opens.
document.addEventListener('click', (e) => {
    if (!e.target.closest('[data-open-modal="cdCheckinModal"]')) return;
    const search = document.getElementById('cd-ci-search');
    const results = document.getElementById('cd-ci-results');
    const form = document.getElementById('cdCheckinForm');
    if (search) search.value = '';
    if (results) results.hidden = true;
    if (form) form.hidden = true;

    // PHASE 9 ITEM 2. Clear the stand-in prompt with everything else, or a
    // reopened modal shows the previous family's radio group.
    cdCheckinHousehold = null;
    const acting = document.getElementById('cd-ci-acting');
    const actingOptions = document.getElementById('cd-ci-acting-options');
    if (acting) acting.hidden = true;
    if (actingOptions) actingOptions.innerHTML = '';

    // PHASE 8 ITEM 2. Reset, then fill. The clear above still runs first so a
    // stale household cannot sit under a fresh candidate list.
    runCheckinSearch('');
});

// ---------------------------------------------------------------------
// Edit Family -- opens IN PLACE on this page
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

    // PHASE 7 item 2. Dispatched AFTER the tag checkboxes are restored, and
    // that order is load-bearing: sex-fields.js unticks the female-only boxes
    // when the sex is not female, so running it first would let the tag loop
    // immediately re-tick a hidden box and post a value the server rejects.
    // Dispatched rather than called because sex-fields.js listens by
    // delegation on document; a programmatic value assignment fires nothing.
    row.querySelector('[data-field="sex"]').dispatchEvent(new Event('change', { bubbles: true }));

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
    // DROP 1 -- see the matching note in staff.js. Guarded for the same reason.
    const cdSep = document.getElementById('cd-ed-separated');
    if (cdSep) cdSep.checked = !!data.is_separated;
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
/* PHASE 8 ITEM 2. Captured so the reset-on-open handler below can prefill.
   searchReliefRecipients() is already scoped to households CHECKED IN at this
   shelter, so a blank term lists exactly who relief can be handed to. */
const runReliefSearch = wireSearch('cd-dist-search', 'cd-dist-results', 'reliefSearchUrl', (row) => {
    const li = document.createElement('li');
    li.textContent = row.head + cdMatchedSuffix(row) + ' \u00B7 ' + row.size + ' present';
    return pickable(li, () => {
        document.getElementById('cd-dist-household-id').value = row.id;
        document.getElementById('cd-dist-code').textContent = row.code;
        document.getElementById('cd-dist-head').textContent = row.head;
        const results = document.getElementById('cd-dist-results');
        const form = document.getElementById('cdDistributeForm');
        if (results) results.hidden = true;
        if (form) form.hidden = false;
        // DROP B2. The search row carries only id/code/head/size, so the
        // composition panel needs the full household. Same endpoint the edit
        // and view modals already use.
        cdLoadComposition(row.id);
    });
}, {
    term: 'No checked-in household matches that name.',
    blank: 'No household is checked in at this shelter yet.',
});

/**
 * DROP B2. Fetch a household and render the composition panel beside the item
 * rows, so the CSWD Office sees the same family facts a camp manager does.
 *
 * Failure is non-fatal and SILENT ON SCREEN by design: the panel is an aid, and
 * a family that cannot be summarised must not block a distribution that is
 * otherwise valid. The reason goes to the console, which is where every other
 * failure path in this file reports.
 */
async function cdLoadComposition(id) {
    const box = document.getElementById('cd-dist-composition');
    if (!box) return;
    try {
        const c = cfg();
        if (!c) return;
        const data = await cdJson(c.householdUrlTemplate.replace(':id', id));
        renderComposition(box, data);
    } catch (err) {
        box.hidden = true;
        console.error(TAG + ' could not load household composition for the distribute modal.', err);
    }
}

/* DROP B2. Restore after a REJECTED submit. Blade renders this modal and its
   form already open when the last attempt failed validation, with household_id
   and the item rows re-emitted from old(). The header and the composition panel
   come from a fetch, so they are refilled here.

   NOT wired to the modal-open click handler above -- that handler RESETS the
   form and drops extra item rows, which is exactly what must not happen to a
   rejected submission the operator is trying to correct. */
document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('cdDistributeForm');
    const idInput = document.getElementById('cd-dist-household-id');
    if (!form || form.hidden || !idInput || !idInput.value) return;

    const itemBox = document.getElementById('cd-dist-items');
    if (itemBox) {
        // Advance past the rows Blade rendered, or the next "Add another item"
        // reuses an index already in the form and overwrites a quantity.
        distItemIndex = Math.max(distItemIndex, itemBox.querySelectorAll('[data-item-row]').length);
        cdSyncRemoveButtons();
    }
    cdLoadComposition(idInput.value);
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

    // PHASE 8 ITEM 2. Reset, then fill -- same order as the check-in modal.
    runReliefSearch('');
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
