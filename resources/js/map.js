/* =========================================================================
   EvacTech -- Leaflet entry point (roadmap item 3: no CDNs, works offline).

   Loaded only by the public evacuation map, via
   `$viteEntries = ['resources/js/map.js']` at the top of that view, so citizens
   on Find Family and Hotlines never download Leaflet and staff screens never
   download it at all.

   THREE THINGS THAT BREAK WHEN LEAFLET IS BUNDLED INSTEAD OF CDN-LOADED
   ---------------------------------------------------------------------
   1. Tailwind Preflight sets `img { max-width: 100% }`, which shears tiles out
      of alignment. `.leaflet-container img { max-width: none }` already lives in
      design-system.css. Leaflet's own stylesheet is imported here from JS, so
      Vite emits it UNLAYERED and it therefore beats Preflight's @layer base --
      importing it into a Tailwind layer instead would lose that fight.

   2. `L.marker()` uses L.Icon.Default, which derives its PNG paths from the
      location of leaflet.css. Under a bundler those paths resolve to nowhere and
      you get an invisible marker with a broken-image outline. The usual fix is
      to re-point the icon URLs at Vite asset imports; this file takes the better
      option for an offline-first app and uses circleMarker everywhere, so the
      map depends on no image files at all.

   3. Tiles are still fetched from openstreetmap.org at runtime. Bundling Leaflet
      cannot fix that -- caching tiles is roadmap item 4. Until then the map says
      so out loud rather than presenting an unexplained grey rectangle: a citizen
      who cannot see the map needs to know it is the map that is broken, not
      that there are no shelters.
   ========================================================================= */

import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

/* Cabuyao city centre. Used until shelter coordinates are known, and as the
   fallback when no shelter has been given a location yet. */
const CABUYAO = [14.2726, 121.1262];

function token(name, fallback) {
    const value = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    return value || fallback;
}

function tierFill() {
    return {
        ok: token('--color-success', '#15803d'),
        warn: token('--color-warning', '#b45309'),
        full: token('--color-danger', '#b91c1c'),
        /* Overcapacity is black, never a louder red -- it is its own state.
           Roadmap item 14. */
        over: token('--color-over', '#111827'),
        unknown: token('--color-ink-muted', '#64748b'),
    };
}

function readShelters() {
    const island = document.getElementById('shelterMapData');
    if (!island) return [];
    try {
        const parsed = JSON.parse(island.textContent);
        return Array.isArray(parsed) ? parsed : [];
    } catch (err) {
        console.error('[EvacTech] shelter map data is not valid JSON.', err);
        return [];
    }
}

function haversineKm(lat1, lng1, lat2, lng2) {
    const R = 6371;
    const rad = Math.PI / 180;
    const dLat = (lat2 - lat1) * rad;
    const dLng = (lng2 - lng1) * rad;
    const a = Math.sin(dLat / 2) ** 2
        + Math.cos(lat1 * rad) * Math.cos(lat2 * rad) * Math.sin(dLng / 2) ** 2;
    return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
}

/* Escape before interpolating shelter names and addresses into popup HTML.
   The values come from the database via a JSON island, so they are data, not
   markup, and a shelter named with an ampersand or an angle bracket must not be
   able to reshape the popup. */
function esc(value) {
    return String(value === null || value === undefined ? '' : value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function initMap() {
    const host = document.getElementById('shelterMap');
    if (!host) return;

    const shelters = readShelters();
    const offlineNote = document.getElementById('mapOfflineNote');
    const fills = tierFill();

    const map = L.map(host, { scrollWheelZoom: false }).setView(CABUYAO, 13);

    /* scrollWheelZoom is off because the map sits inside a scrolling page. On a
       phone a two-finger drag still pans and pinch still zooms; on a desktop a
       stray wheel over the map used to swallow the page scroll. */
    map.once('focus', () => map.scrollWheelZoom.enable());

    const tiles = L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap contributors',
    }).addTo(map);

    /* ---- Honest failure when tiles cannot be reached ----
       One failed tile is not a diagnosis (a single 404 at the edge of a pan is
       normal), so wait for a few before saying anything. Recovering when the
       connection returns matters as much as reporting the loss. */
    let tileErrors = 0;
    const showOffline = () => {
        if (offlineNote) offlineNote.hidden = false;
    };
    const hideOffline = () => {
        if (offlineNote) offlineNote.hidden = true;
    };

    tiles.on('tileerror', () => {
        tileErrors += 1;
        if (tileErrors >= 3) showOffline();
    });
    tiles.on('load', () => {
        tileErrors = 0;
        hideOffline();
    });

    if (!navigator.onLine) showOffline();
    window.addEventListener('offline', showOffline);
    window.addEventListener('online', () => {
        tileErrors = 0;
        tiles.redraw();
    });

    /* ---- Shelter markers ----
       circleMarker, not marker: no image dependency, and the radius scales with
       nothing so it stays legible at every zoom. Colour carries the capacity
       tier, and the popup repeats it in words, because colour is never the only
       carrier of status in this system. */
    const pinned = shelters.filter((s) => s.lat !== null && s.lng !== null);

    pinned.forEach((s) => {
        const marker = L.circleMarker([s.lat, s.lng], {
            radius: 10,
            color: token('--color-bg', '#ffffff'),
            weight: 2,
            fillColor: fills[s.tier] || fills.unknown,
            fillOpacity: 0.95,
        }).addTo(map);

        const occupancy = s.pct !== null
            ? `${s.occupancy} / ${s.capacity} (${s.pct}%)`
            : 'Capacity not set';
        const phone = s.contact_number
            ? `<br>Phone: <a href="tel:${esc(String(s.contact_number).replace(/[^0-9+]/g, ''))}">${esc(s.contact_number)}</a>`
            : '';

        marker.bindPopup(
            `<strong>${esc(s.name)}</strong><br>Barangay ${esc(s.barangay)}<br>`
            + `${esc(s.address)}<br>Status: ${esc(s.tier_label)}<br>`
            + `Occupancy: ${esc(occupancy)}${phone}`
        );
        marker.bindTooltip(`${esc(s.name)} -- ${esc(s.tier_label)}`);
    });

    if (pinned.length > 0) {
        map.fitBounds(pinned.map((s) => [s.lat, s.lng]), { padding: [40, 40], maxZoom: 14 });
    }

    /* ---- Nearest shelters ---- */
    const locateBtn = document.getElementById('locateBtn');
    const hint = document.getElementById('locateHint');
    const panel = document.getElementById('nearestPanel');
    const list = document.getElementById('nearestList');
    if (!locateBtn) return;

    let userMarker = null;

    locateBtn.addEventListener('click', () => {
        if (!navigator.geolocation) {
            hint.textContent = 'Location is not supported by this browser. The city-wide map below still works.';
            return;
        }
        hint.textContent = 'Finding your location...';

        navigator.geolocation.getCurrentPosition((pos) => {
            const { latitude: lat, longitude: lng } = pos.coords;
            hint.textContent = '';

            if (userMarker) map.removeLayer(userMarker);
            userMarker = L.circleMarker([lat, lng], {
                radius: 8,
                color: token('--color-bg', '#ffffff'),
                weight: 3,
                fillColor: token('--color-primary-600', '#0891b2'),
                fillOpacity: 1,
            }).addTo(map).bindPopup('You are here').openPopup();
            map.setView([lat, lng], 14);

            if (pinned.length === 0) {
                hint.textContent = 'No shelter has a map location recorded yet, so distances cannot be calculated.';
                return;
            }

            const nearest = pinned
                .map((s) => ({ ...s, km: haversineKm(lat, lng, s.lat, s.lng) }))
                .sort((a, b) => a.km - b.km)
                .slice(0, 5);

            /* Real buttons, not click handlers on bare <li> elements. The old
               version was unreachable by keyboard and invisible to a screen
               reader, on the one control a citizen most needs. */
            // Utility classes, not a stylesheet class. app.css declares
            // @source '../**/*.js', so Tailwind scans this file and compiles the
            // classes named below -- which keeps JS-generated markup on the same
            // design tokens as Blade-generated markup, with no orphan CSS that
            // only one code path can reach.
            //
            // Line comments, not a block comment: the */ inside that glob closes
            // a /* */ comment early. It did exactly that here, and the resulting
            // syntax error is what the brace-balance check caught.
            list.innerHTML = '';
            nearest.forEach((s) => {
                const li = document.createElement('li');
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'flex w-full min-h-tap flex-col items-start gap-0.5 '
                    + 'border-b border-border px-2 py-3 text-left text-sm '
                    + 'hover:bg-surface';
                btn.innerHTML =
                    `<span class="font-semibold text-ink">${esc(s.name)}</span>`
                    + `<span class="text-ink-soft">${esc(s.address)}</span>`
                    + `<span class="text-ink-muted" data-numeric>${s.km.toFixed(1)} km away`
                    + ` &middot; ${esc(s.tier_label)}</span>`;
                btn.addEventListener('click', () => {
                    map.setView([s.lat, s.lng], 16);
                    host.scrollIntoView({ behavior: 'smooth', block: 'center' });
                });
                li.appendChild(btn);
                list.appendChild(li);
            });
            panel.hidden = nearest.length === 0;
        }, (err) => {
            hint.textContent = err.code === err.PERMISSION_DENIED
                ? 'Location permission was denied. The city-wide map below still works.'
                : 'Could not determine your location. The city-wide map below still works.';
        }, { enableHighAccuracy: true, timeout: 10000 });
    });
}

document.addEventListener('DOMContentLoaded', initMap);
