// ---------------------------------------------------------------------
// PHASE 2 ITEM 8 -- Shelter transfers.
//
// Kept out of staff.js and cityadmin-shelter.js on purpose: this file is loaded
// by BOTH roles, on four different pages, and the barangay and city action
// wiring must not get tangled together again.
//
// ---------------------------------------------------------------------------
// HARDENING NOTE (v2)
//
// v1 of this file bound its handlers inside a DOMContentLoaded callback behind
// `if (!window.TransferConfig) return;`, and every button did nothing with no
// message in the console -- the same silent failure cityadmin-shelter.js
// already documents. Repeating that pattern was the bug.
//
// v2 follows cityadmin-shelter.js instead: EVENT DELEGATION on `document`, so
// nothing depends on script load order, on when the modals are pushed onto the
// stack, or on rows existing at load time. Every failure path now says exactly
// what is wrong in the console rather than doing nothing.
// ---------------------------------------------------------------------------

const TAG = '[EvacTech/transfers]';

function cfg() {
    const c = window.TransferConfig;
    if (!c) {
        console.error(
            TAG + ' window.TransferConfig is missing. The inline config block in ' +
            'the page did not run. Check that the page pushes to the scripts ' +
            'stack and that the layout renders the scripts stack.'
        );
        return null;
    }
    return c;
}

function modal(id) {
    const el = document.getElementById(id);
    if (!el) {
        console.error(
            TAG + ' #' + id + ' is not on the page. The page is missing ' +
            'the transfer-modals partial in its modals stack.'
        );
    }
    return el;
}

function show(id) {
    const el = modal(id);
    if (el) el.hidden = false;
}

function fill(id, text) {
    const el = document.getElementById(id);
    if (el) el.textContent = text;
}

// ---------------------------------------------------------------------
// New transfer
// ---------------------------------------------------------------------

// The full list of active shelters is sent to the browser and the ORIGIN is
// filtered out here, because on the Transfers page the origin is not known until
// a household has been picked.
function renderDestinations(originId) {
    const c = cfg();
    const select = document.getElementById('tx-destination');
    if (!c || !select) return;

    select.innerHTML = '';
    const blank = document.createElement('option');
    blank.value = '';
    blank.textContent = 'Select a shelter';
    select.appendChild(blank);

    const centers = c.centers || [];
    if (centers.length === 0) {
        console.warn(TAG + ' no active shelters were passed to the destination picker.');
    }

    centers
        .filter((s) => Number(s.id) !== Number(originId))
        .forEach((s) => {
            const opt = document.createElement('option');
            opt.value = s.id;
            const where = s.barangay ? ' \u00B7 Brgy. ' + s.barangay : '';
            // Overcapacity shelters stay selectable and say so, rather than being
            // hidden: in a real evacuation there may be nowhere else to send people.
            const load = s.over
                ? ' \u00B7 OVER CAPACITY ' + s.occupancy + '/' + s.capacity
                : ' \u00B7 ' + s.occupancy + '/' + s.capacity;
            opt.textContent = s.name + where + load;
            select.appendChild(opt);
        });
}

function loadIntoCreateForm(data) {
    const c = cfg();
    const form = document.getElementById('transferCreateForm');
    if (!c || !form) return;

    const idField = document.getElementById('tx-household-id');
    if (idField) idField.value = data.id;

    fill('tx-code', data.code || '');
    fill('tx-head', data.head ? '\u00B7 ' + data.head : '');
    fill(
        'tx-origin',
        'Currently at ' + (data.center || 'an unknown shelter') + ' with ' + data.present + ' present'
    );

    renderDestinations(data.center_id);

    form.action = c.store;
    form.hidden = false;

    const results = document.getElementById('tx-results');
    if (results) results.hidden = true;
}

function openCreate(btn) {
    const c = cfg();
    if (!c) return;

    const form = document.getElementById('transferCreateForm');
    const picker = document.getElementById('txPicker');
    if (!form || !picker) {
        modal('transferCreateModal');
        return;
    }

    const id = btn.dataset.householdId;

    if (id) {
        // Opened from a household row: the family is already known.
        picker.hidden = true;
        loadIntoCreateForm({
            id: id,
            code: btn.dataset.householdCode,
            head: btn.dataset.householdHead,
            present: btn.dataset.householdPresent,
            center: btn.dataset.centerName,
            center_id: btn.dataset.centerId,
        });
    } else {
        // Opened from the Transfers page: pick a household first.
        picker.hidden = false;
        form.hidden = true;
        const search = document.getElementById('tx-search');
        const results = document.getElementById('tx-results');
        if (search) search.value = '';
        if (results) {
            results.hidden = true;
            results.innerHTML = '';
        }
    }

    show('transferCreateModal');
}

async function runHouseholdSearch(term) {
    const c = cfg();
    const results = document.getElementById('tx-results');
    if (!c || !results) return;

    try {
        const res = await fetch(c.search + '?q=' + encodeURIComponent(term), {
            headers: { Accept: 'application/json' },
        });
        if (!res.ok) {
            console.error(TAG + ' household search failed with HTTP ' + res.status);
            return;
        }
        const items = await res.json();

        results.innerHTML = '';
        items.forEach((item) => {
            const li = document.createElement('li');
            if (item.has_open_transfer) {
                // Already moving. Shown but not selectable, so staff can see why
                // the family they are looking for cannot be transferred again.
                li.textContent = item.code + ' \u00B7 ' + item.head + ' \u00B7 transfer already in progress';
                li.setAttribute('aria-disabled', 'true');
            } else {
                li.textContent = item.code + ' \u00B7 ' + item.head + ' \u00B7 ' +
                    item.present + ' present \u00B7 ' + item.center;
                li.addEventListener('click', () => loadIntoCreateForm(item));
            }
            results.appendChild(li);
        });
        results.hidden = items.length === 0;
    } catch (err) {
        console.error(TAG + ' household search error', err);
    }
}

// ---------------------------------------------------------------------
// Receive: the arrival checklist
// ---------------------------------------------------------------------
async function openReceive(btn) {
    const c = cfg();
    if (!c) return;

    const form = document.getElementById('transferReceiveForm');
    const list = document.getElementById('tx-rc-members');
    if (!form || !list) {
        modal('transferReceiveModal');
        return;
    }

    const id = btn.dataset.txReceive;
    form.action = c.receive.replace(':id', id);
    list.innerHTML = '';
    show('transferReceiveModal');

    try {
        const res = await fetch(c.members.replace(':id', id), {
            headers: { Accept: 'application/json' },
        });
        if (!res.ok) {
            console.error(TAG + ' could not load the arrival checklist, HTTP ' + res.status);
            return;
        }
        const data = await res.json();

        fill('tx-rc-code', data.code || '');
        fill('tx-rc-head', data.head ? '\u00B7 ' + data.head : '');
        fill('tx-rc-expected', data.expected + ' people departed the origin shelter');

        (data.members || []).forEach((m) => {
            const row = document.createElement('div');

            const label = document.createElement('label');
            label.className = 'checkbox-row';
            const head = m.is_head ? ' (head)' : '';
            // Everyone marked present at the origin is pre-ticked; staff untick
            // anyone who did not make it.
            const checked = m.is_present ? ' checked' : '';
            label.innerHTML =
                '<input type="checkbox" name="present[]" value="' + m.id + '"' + checked + '> ' +
                m.name + head;
            row.appendChild(label);

            // PHASE 5 ITEM 8b -- the reason, but ONLY for someone who was
            // actually present at the origin. A member who was already absent
            // before the transfer was never on the truck, so asking why they did
            // not arrive would be asking about a journey they never started. The
            // server applies the same rule.
            //
            // Name is reasons[<id>]: ONE bracket pair. PHP closes a form key at
            // the first ], so reasons[<id>[]] would arrive as the literal string
            // key "reasons[" and nothing would ever reach the server.
            if (m.is_present) {
                const select = document.createElement('select');
                select.name = 'reasons[' + m.id + ']';
                select.className = 'mt-1';
                select.setAttribute('aria-label', 'Reason ' + m.name + ' did not arrive');
                select.innerHTML =
                    '<option value="">Why did they not arrive?</option>' +
                    ABSENCE_REASONS.map(
                        (r) => '<option value="' + r.code + '">' + r.label + '</option>'
                    ).join('');
                row.appendChild(select);
            }

            list.appendChild(row);
        });

        syncReceiveReasons();
    } catch (err) {
        console.error(TAG + ' arrival checklist error', err);
    }
}

// Reason codes must match ShelterTransfer::ABSENCE_REASONS. Only 'unknown'
// raises anything: three of the four answers mean nothing is wrong, which is
// what keeps the alert rare enough to still mean something.
const ABSENCE_REASONS = [
    { code: 'separate', label: 'Travelled separately, expected later' },
    { code: 'returned_home', label: 'Returned home' },
    { code: 'other_shelter', label: 'Went to another shelter' },
    { code: 'unknown', label: 'Unknown' },
];

// A reason select is shown ONLY for someone who is not ticked, and is disabled
// whenever it is hidden.
//
// The disabling is not cosmetic: a hidden field carrying `required` blocks form
// submission in Chrome with no visible message and no console error, which is
// exactly the class of silent failure this project keeps hitting. Disabling also
// keeps the field out of the POST entirely, so the server never receives a
// reason for somebody who did arrive.
function syncReceiveReasons() {
    const list = document.getElementById('tx-rc-members');
    if (!list) return;

    list.querySelectorAll('select[name^="reasons["]').forEach((select) => {
        const row = select.parentElement;
        const box = row ? row.querySelector('input[type="checkbox"]') : null;
        if (!box) return;

        const arrived = box.checked;
        select.hidden = arrived;
        select.disabled = arrived;
        select.required = !arrived;
        if (arrived) select.value = '';
    });
}

// ---------------------------------------------------------------------
// PHASE 5 ITEM 8b -- Resolve an absence.
// ---------------------------------------------------------------------
function openResolve(btn) {
    const c = cfg();
    if (!c) return;

    if (!c.resolve) {
        console.error(
            TAG + ' TransferConfig.resolve is missing. The page built its $tx ' +
            'array without the resolve route template.'
        );
        return;
    }

    const form = document.getElementById('transferResolveForm');
    const memberField = document.getElementById('tx-rs-member-id');
    if (!form || !memberField) {
        modal('transferResolveModal');
        return;
    }

    form.action = c.resolve.replace(':id', btn.dataset.txResolve);
    memberField.value = btn.dataset.txMember || '';
    fill('tx-rs-name', btn.dataset.txMemberName || 'This person');

    const select = document.getElementById('tx-rs-resolution');
    if (select) select.value = '';

    show('transferResolveModal');
}

// ---------------------------------------------------------------------
// Refuse and cancel
// ---------------------------------------------------------------------
function openRefuse(btn) {
    const c = cfg();
    const form = document.getElementById('transferRefuseForm');
    if (!c || !form) {
        modal('transferRefuseModal');
        return;
    }

    form.action = c.refuse.replace(':id', btn.dataset.txRefuse);
    fill('tx-rf-label', btn.dataset.txLabel || 'this household');
    const reason = document.getElementById('tx-rf-reason');
    if (reason) reason.value = '';
    show('transferRefuseModal');
}

function openCancel(btn) {
    const c = cfg();
    const form = document.getElementById('transferCancelForm');
    if (!c || !form) {
        modal('transferCancelModal');
        return;
    }

    form.action = c.cancel.replace(':id', btn.dataset.txCancel);
    fill('tx-cl-label', btn.dataset.txLabel || 'this household');

    const reason = document.getElementById('tx-cl-reason');
    const optional = document.getElementById('tx-cl-optional');
    const transitNote = document.getElementById('tx-cl-transit-note');

    // Cancelling a family who has already departed is City Admin only and must
    // say what happened to them. The server enforces this too.
    const inTransit = btn.dataset.txInTransit === '1';
    if (reason) {
        reason.value = '';
        reason.required = inTransit;
    }
    if (transitNote) transitNote.hidden = !inTransit;
    if (optional) optional.hidden = inTransit;

    show('transferCancelModal');
}

// ---------------------------------------------------------------------
// PHASE 5 ITEM 8b -- Update Presence.
//
// Lives in THIS file rather than staff.js or cityadmin-shelter.js because
// transfers.js is imported by app.js and is therefore already loaded on both
// shelter screens. Putting it in either of the others would mean one of the two
// roles could not open the modal, or would mean calling openModal()/cdOpen()
// across an ES module boundary by bare name -- a ReferenceError, and the exact
// bug that hid three features for a whole phase.
//
// The URLs ride on the SAME window.TransferConfig the transfer modals use, so
// the page still has exactly one raw JSON echo in it.
// ---------------------------------------------------------------------

// Enables or disables Save from the live tick count, so the "at least one
// person" rule is visible before submit rather than only after a round trip.
// The server enforces it regardless -- this is convenience, not the guard.
function syncPresenceState() {
    const list = document.getElementById('pr-members');
    const save = document.getElementById('pr-save');
    const warning = document.getElementById('pr-none-warning');
    if (!list) return;

    const ticked = list.querySelectorAll('input[type="checkbox"]:checked').length;

    if (save) save.disabled = ticked === 0;
    if (warning) warning.hidden = ticked !== 0;
}

async function openPresence(btn) {
    const c = cfg();
    if (!c) return;

    const form = document.getElementById('presenceForm');
    const list = document.getElementById('pr-members');
    const blocked = document.getElementById('pr-blocked');
    if (!form || !list || !blocked) {
        modal('presenceModal');
        return;
    }

    if (!c.presence || !c.presenceSave) {
        console.error(
            TAG + ' TransferConfig.presence / .presenceSave are missing. The page ' +
            'built its $txConfig array without the presence route templates.'
        );
        return;
    }

    const id = btn.dataset.presence;

    // Reset before showing: a modal reopened on a second household must never
    // display the first one's tick list while the fetch is in flight.
    list.innerHTML = '';
    form.hidden = true;
    blocked.hidden = true;
    fill('pr-code', '');
    fill('pr-head', '');
    fill('pr-summary', 'Loading...');
    show('presenceModal');

    try {
        const res = await fetch(c.presence.replace(':id', id), {
            headers: { Accept: 'application/json' },
        });
        if (!res.ok) {
            console.error(TAG + ' could not load the presence list, HTTP ' + res.status);
            fill('pr-summary', 'Could not load this household. Reload the page and try again.');
            return;
        }
        const data = await res.json();

        fill('pr-code', data.code || '');
        fill('pr-head', data.head ? '\u00B7 ' + data.head : '');
        fill('pr-summary', data.present + ' of ' + data.total + ' currently counted present at ' +
            (data.center || 'this shelter'));

        // Blocked: show why and never render the form. Almost always an open
        // shelter transfer, which has already snapshotted the headcount.
        if (data.blocked) {
            fill('pr-blocked-text', data.blocked);
            blocked.hidden = false;
            return;
        }

        form.action = c.presenceSave.replace(':id', id);

        (data.members || []).forEach((m) => {
            const label = document.createElement('label');
            label.className = 'checkbox-row';
            const head = m.is_head ? ' (head)' : '';
            const checked = m.is_present ? ' checked' : '';
            label.innerHTML =
                '<input type="checkbox" name="present[]" value="' + m.id + '"' + checked + '> ' +
                m.name + head;
            list.appendChild(label);
        });

        form.hidden = false;
        syncPresenceState();
    } catch (err) {
        console.error(TAG + ' presence list error', err);
        fill('pr-summary', 'Could not load this household. Reload the page and try again.');
    }
}

// ---------------------------------------------------------------------
// Delegation. One click listener for the whole module.
// ---------------------------------------------------------------------
document.addEventListener('click', (e) => {
    const resolve = e.target.closest('[data-tx-resolve]');
    if (resolve) {
        openResolve(resolve);
        return;
    }

    const presence = e.target.closest('[data-presence]');
    if (presence) {
        openPresence(presence);
        return;
    }

    const create = e.target.closest('[data-tx-create]');
    if (create) {
        openCreate(create);
        return;
    }

    const receive = e.target.closest('[data-tx-receive]');
    if (receive) {
        openReceive(receive);
        return;
    }

    const refuse = e.target.closest('[data-tx-refuse]');
    if (refuse) {
        openRefuse(refuse);
        return;
    }

    const cancel = e.target.closest('[data-tx-cancel]');
    if (cancel) {
        openCancel(cancel);
        return;
    }

    if (e.target.closest('#tx-search-btn')) {
        const search = document.getElementById('tx-search');
        runHouseholdSearch(search ? search.value.trim() : '');
    }
});

// Delegated, so both work on checkboxes that did not exist at load time.
document.addEventListener('change', (e) => {
    if (e.target.closest('#pr-members')) {
        syncPresenceState();
        return;
    }
    if (e.target.closest('#tx-rc-members')) {
        syncReceiveReasons();
    }
});

let txSearchDebounce;
document.addEventListener('input', (e) => {
    if (!e.target.closest('#tx-search')) return;
    const value = e.target.value.trim();
    clearTimeout(txSearchDebounce);
    txSearchDebounce = setTimeout(() => runHouseholdSearch(value), 250);
});

// Enter inside the picker should search, not submit the surrounding page.
document.addEventListener('keydown', (e) => {
    if (e.key !== 'Enter') return;
    if (!e.target.closest('#tx-search')) return;
    e.preventDefault();
    runHouseholdSearch(e.target.value.trim());
});
