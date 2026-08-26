/* =========================================================================
   EvacTech -- Shelter location picker (Phase 3 item 10).

   City Admin drops a pin instead of typing coordinates. Loaded ONLY by
   cityadmin/shelters/index.blade.php, via
   `$viteEntries = ['resources/js/shelter-picker.js']`.

   WHY THIS IS A SEPARATE ENTRY FROM map.js
   ----------------------------------------
   map.js is the citizen-facing evacuation map: read-only markers, popups,
   geolocation, nearest-shelter list, offline notice. This file is a placement
   tool: one draggable pin, faint reference pins, and two form inputs it keeps in
   sync. The two share only a CSS-variable reader and a tile layer; everything
   else diverges. Two entries importing Leaflet do NOT ship it twice -- Rollup
   hoists the shared dependency into a common chunk.

   token() below is deliberately duplicated from map.js rather than extracted to
   a third module. It is a pure DOM read with no business rules in it; if it ever
   drifted, the symptom would be a wrong colour, visible immediately. That is a
   different risk from the duplicated syncMembers() methods, which encoded rules
   that silently disagreed.

   HOW IT TALKS TO THE FORM
   ------------------------
   cityadmin.js owns the Add/Edit Shelter form and dispatches ONE event on
   `document` after it has finished populating and opening the modal:

       evactech:shelter-form-open
       detail: { mode, id, latitude, longitude, barangayId }

   This file listens for that event and never reads the form's own buttons. That
   removes any dependency on which module's listener registered first, which
   would otherwise be decided by <script> execution order across two entries.

   THREE THINGS THAT BREAK IF YOU CHANGE THIS CARELESSLY
   ----------------------------------------------------
   1. A Leaflet map created inside a hidden container renders as a grey box, and
      one created while the container has zero height never recovers on its own.
      The map is therefore created LAZILY, on the first form-open event, and
      invalidateSize() runs on every open after that.
   2. L.marker() uses L.Icon.Default, whose PNG paths are derived from the
      location of leaflet.css and resolve to nowhere under a bundler. map.js
      avoided that with circleMarker, but circleMarker cannot be dragged. This
      file uses a divIcon instead: no image files, and dragging works.
   3. Reference markers are interactive: false so a click always reaches the map
      underneath. A marker that swallowed the click would make it impossible to
      place a pin on top of an existing shelter.
   ========================================================================= */

import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

const TAG = '[EvacTech/shelter-picker]';

/* Cabuyao city centre. Same value as map.js: the fallback view when neither the
   shelter being edited nor its barangay has any known coordinates. */
const CABUYAO = [14.2726, 121.1262];

/* Above this zoom, reference shelters show their names. Below it, 30-plus
   permanent tooltips across one city is unreadable, so they are just dots. */
const LABEL_ZOOM = 14;

/* Announce that this module loaded at all. If this line is absent from the
   console, shelter-picker.js is not in the built bundle: check $viteEntries at
   the top of cityadmin/shelters/index.blade.php, check that the entry is listed
   in vite.config.js, and re-run `npm run build`. */
console.info(TAG + ' loaded');

let map = null;
let pin = null;
let referenceLayer = null;
let referenceShelters = [];
let currentEditId = null;
let labelsShown = false;

function token(name, fallback) {
    const value = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    return value || fallback;
}

function el(id) {
    return document.getElementById(id);
}

/* ---------------------------------------------------------------------
   Data island
   --------------------------------------------------------------------- */
function readShelters() {
    const island = el('shelterPickerData');
    if (!island) {
        console.error(
            TAG + ' #shelterPickerData is missing, so no existing shelters can be '
            + 'shown for reference. The picker still works. Check that the view '
            + 'renders the data island in its @push(\'scripts\') block.'
        );
        return [];
    }
    try {
        const parsed = JSON.parse(island.textContent);
        return Array.isArray(parsed) ? parsed : [];
    } catch (err) {
        console.error(TAG + ' #shelterPickerData is not valid JSON.', err);
        return [];
    }
}

/* ---------------------------------------------------------------------
   The draggable pin
   --------------------------------------------------------------------- */
/* divIcon, not the default marker icon: no PNG to resolve under a bundler, and
   the colours come from the same design tokens as the rest of the system, so it
   themes correctly in dark mode. Inline style rather than utility classes
   because this markup is handed to Leaflet, not to Tailwind's scanner, and it
   must be correct even if a class name is ever purged. */
function pinIcon() {
    const fill = token('--color-primary-600', '#0891b2');
    const ring = token('--color-bg', '#ffffff');
    const html =
        '<span style="display:block;width:26px;height:26px;border-radius:50%;'
        + 'background:' + fill + ';border:3px solid ' + ring + ';'
        + 'box-shadow:0 2px 6px rgba(15,23,42,0.45);"></span>';

    return L.divIcon({
        html,
        className: '',
        iconSize: [26, 26],
        iconAnchor: [13, 13],
    });
}

function latInput() {
    return el('sh-lat');
}

function lngInput() {
    return el('sh-lng');
}

/* Round to 6 decimal places. The column is decimal(10,7), and 6 places is about
   0.1m -- far finer than a pin dropped by hand, and it keeps the read-out
   legible instead of showing fifteen digits of floating-point noise. */
function round6(n) {
    return Math.round(n * 1e6) / 1e6;
}

function writeInputs(lat, lng) {
    const a = latInput();
    const b = lngInput();
    if (!a || !b) {
        console.error(TAG + ' #sh-lat / #sh-lng not found; the pin cannot be saved.');
        return;
    }
    a.value = lat === null ? '' : String(round6(lat));
    b.value = lng === null ? '' : String(round6(lng));
}

function readInputs() {
    const a = latInput();
    const b = lngInput();
    if (!a || !b) return null;
    if (a.value.trim() === '' || b.value.trim() === '') return null;
    const lat = parseFloat(a.value);
    const lng = parseFloat(b.value);
    if (Number.isNaN(lat) || Number.isNaN(lng)) return null;
    if (lat < -90 || lat > 90 || lng < -180 || lng > 180) return null;
    return [lat, lng];
}

function setStatus(message) {
    const box = el('shelterPickerStatus');
    if (box) box.textContent = message;
}

function statusForPin() {
    const at = readInputs();
    if (!at) {
        setStatus('No location set. Click the map to place this shelter.');
    } else {
        setStatus('Location set at ' + round6(at[0]) + ', ' + round6(at[1])
            + '. Drag the pin or click elsewhere to move it.');
    }
    const clear = el('shelterPickerClear');
    if (clear) clear.disabled = !at;
}

function placePin(lat, lng, opts) {
    const options = opts || {};
    if (!map) return;

    if (!pin) {
        pin = L.marker([lat, lng], {
            icon: pinIcon(),
            draggable: true,
            keyboard: true,
            title: 'Shelter location. Drag to move.',
            alt: 'Shelter location pin',
        }).addTo(map);

        pin.on('dragend', () => {
            const p = pin.getLatLng();
            writeInputs(p.lat, p.lng);
            statusForPin();
        });
    } else {
        pin.setLatLng([lat, lng]);
    }

    if (options.silent !== true) {
        writeInputs(lat, lng);
    }
    statusForPin();
}

function removePin() {
    if (pin && map) map.removeLayer(pin);
    pin = null;
    writeInputs(null, null);
    statusForPin();
}

/* ---------------------------------------------------------------------
   Reference pins -- every other shelter that has coordinates
   --------------------------------------------------------------------- */
function drawReferences(excludeId) {
    if (!map) return;

    if (referenceLayer) map.removeLayer(referenceLayer);
    referenceLayer = L.layerGroup().addTo(map);

    const muted = token('--color-ink-muted', '#64748b');
    const ring = token('--color-bg', '#ffffff');

    /* Names are bound only when the map is zoomed in. Thirty permanent tooltips
       across a whole city is unreadable, and a dot with no name is a much weaker
       landmark, so the threshold buys both. Deciding it here -- and redrawing the
       layer when the threshold is crossed -- keeps it a plain fact about what was
       built, rather than a class being toggled against Leaflet's own stylesheet. */
    labelsShown = map.getZoom() >= LABEL_ZOOM;

    referenceShelters
        .filter((s) => String(s.id) !== String(excludeId))
        .forEach((s) => {
            const dot = L.circleMarker([s.lat, s.lng], {
                radius: 7,
                color: ring,
                weight: 2,
                fillColor: muted,
                fillOpacity: 0.9,
                /* Clicks pass straight through to the map, so a new shelter can
                   be placed right next to -- or on top of -- an existing one. */
                interactive: false,
            });
            if (labelsShown) {
                dot.bindTooltip(s.name, {
                    permanent: true,
                    direction: 'right',
                    offset: [8, 0],
                    className: 'shelter-picker-label',
                });
            }
            referenceLayer.addLayer(dot);
        });
}

/* ---------------------------------------------------------------------
   Framing
   --------------------------------------------------------------------- */
/* Where should the map look when the modal opens, or when the barangay changes?
   In order of preference: the pin, then the chosen barangay's existing shelters,
   then the city centre.

   barangays.latitude / barangays.longitude exist in the schema but are null for
   all 18 -- BarangaySeeder leaves them blank on purpose and no screen fills them
   in. So a barangay's position is derived here from the average of its own
   pinned shelters. When those coordinates are eventually recorded, this function
   is the one place to change. */
function frameFor(barangayId, excludeId) {
    if (!map) return;

    const at = readInputs();
    if (at) {
        map.setView(at, 16);
        return;
    }

    const inBarangay = referenceShelters.filter((s) =>
        String(s.barangay_id) === String(barangayId) && String(s.id) !== String(excludeId));

    if (inBarangay.length === 1) {
        map.setView([inBarangay[0].lat, inBarangay[0].lng], 15);
        return;
    }
    if (inBarangay.length > 1) {
        map.fitBounds(inBarangay.map((s) => [s.lat, s.lng]), {
            padding: [30, 30],
            maxZoom: 16,
        });
        return;
    }

    map.setView(CABUYAO, 13);
}

/* ---------------------------------------------------------------------
   Map creation -- lazy, because the container is hidden until the modal opens
   --------------------------------------------------------------------- */
function ensureMap() {
    if (map) return map;

    const host = el('shelterPickerMap');
    if (!host) {
        console.error(
            TAG + ' #shelterPickerMap not found. The Add/Edit Shelter modal in '
            + 'cityadmin/shelters/index.blade.php must contain the map container.'
        );
        return null;
    }
    if (host.offsetHeight === 0) {
        console.warn(
            TAG + ' #shelterPickerMap has zero height at creation time. Leaflet '
            + 'will render a grey box. The container needs a height utility and '
            + 'the map must be built after the modal is visible.'
        );
    }

    map = L.map(host, {
        scrollWheelZoom: true,
        /* The pin is placed by clicking, so a double-click that also zoomed
           would fight the user for the same gesture. */
        doubleClickZoom: false,
    }).setView(CABUYAO, 13);

    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap contributors',
    }).addTo(map);

    map.on('click', (e) => {
        placePin(e.latlng.lat, e.latlng.lng);
    });

    map.on('zoomend', () => {
        if ((map.getZoom() >= LABEL_ZOOM) !== labelsShown) {
            drawReferences(currentEditId);
        }
    });

    return map;
}

/* ---------------------------------------------------------------------
   Wiring
   --------------------------------------------------------------------- */
referenceShelters = readShelters();

/* The form tells the map what to show. See the header note: one event, so this
   file never depends on script execution order. */
document.addEventListener('evactech:shelter-form-open', (e) => {
    const d = e.detail || {};

    if (!ensureMap()) return;

    currentEditId = d.id ?? null;
    referenceShelters = readShelters();
    drawReferences(currentEditId);

    const hasCoords = d.latitude !== null && d.latitude !== undefined
        && d.longitude !== null && d.longitude !== undefined
        && String(d.latitude) !== '' && String(d.longitude) !== '';

    if (pin) {
        map.removeLayer(pin);
        pin = null;
    }

    if (hasCoords) {
        placePin(parseFloat(d.latitude), parseFloat(d.longitude));
    } else {
        writeInputs(null, null);
        statusForPin();
        if (d.mode === 'edit') {
            setStatus('This shelter has no map location recorded yet. Click the map to place it.');
        }
    }

    /* invalidateSize AFTER the modal is visible, or Leaflet measures a hidden
       container and paints a grey box. Two frames of delay: one for the browser
       to apply the unhidden state, one for layout to settle inside the modal's
       grid. */
    requestAnimationFrame(() => {
        requestAnimationFrame(() => {
            map.invalidateSize();
            frameFor(d.barangayId, currentEditId);
        });
    });
});

/* Typing a coordinate still works, and moves the pin. */
document.addEventListener('input', (e) => {
    if (!e.target.closest('#sh-lat') && !e.target.closest('#sh-lng')) return;
    if (!map) return;

    const at = readInputs();
    if (!at) {
        statusForPin();
        return;
    }
    /* silent: the inputs are already the source of this change; writing them
       back would move the caret while the user is still typing. */
    placePin(at[0], at[1], { silent: true });
    map.setView(at, Math.max(map.getZoom(), 15));
});

/* Changing the barangay reframes the map -- but only when nothing has been
   placed yet. Moving the view out from under an existing pin would look like the
   pin had moved. */
document.addEventListener('change', (e) => {
    const select = e.target.closest('#sh-barangay');
    if (!select || !map) return;

    if (readInputs()) {
        setStatus('Barangay changed. The pin has not moved. Drag it or click the map to '
            + 'place this shelter somewhere else.');
        return;
    }
    frameFor(select.value, currentEditId);
});

document.addEventListener('click', (e) => {
    if (!e.target.closest('#shelterPickerClear')) return;
    e.preventDefault();
    removePin();
    const select = el('sh-barangay');
    frameFor(select ? select.value : null, currentEditId);
});
