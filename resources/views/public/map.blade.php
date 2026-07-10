@extends('layouts.public')

@section('title', 'Evacuation Map')

@push('head')
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
@endpush

@section('content')
<section class="public-hero">
    <h1>Evacuation Centers</h1>
    <p>Find active evacuation shelters across the City of Cabuyao. Allow location access to see the shelters nearest to you.</p>
</section>

<section class="map-section">
    <div class="map-toolbar">
        <button type="button" class="btn-primary" id="locateBtn">Find shelters near me</button>
        <span class="map-hint" id="locateHint"></span>
        <span class="legend">
            <span><i class="dot cap-ok-bg"></i> Space available</span>
            <span><i class="dot cap-warn-bg"></i> Nearing capacity</span>
            <span><i class="dot cap-full-bg"></i> Full</span>
        </span>
    </div>

    <div class="map-layout">
        <div id="shelterMap" class="shelter-map" role="application" aria-label="Map of evacuation shelters"></div>

        <aside class="nearest-panel card" id="nearestPanel" hidden>
            <h2 class="panel-title">Nearest Shelters</h2>
            <ol class="nearest-list" id="nearestList"></ol>
        </aside>
    </div>
</section>

<section class="shelters-section">
    <h2>All Evacuation Shelters</h2>
    <div class="shelter-cards">
        @forelse($shelters as $s)
            <article class="card shelter-card">
                <div class="shelter-card-head">
                    <h3>{{ $s['name'] }}</h3>
                    @php
                        $tierClass = ['ok' => 'badge-success', 'warn' => 'badge-warning', 'full' => 'badge-danger', 'over' => 'badge-danger', 'unknown' => 'badge-info'][$s['tier']];
                        $tierLabel = ['ok' => 'Space available', 'warn' => 'Nearing capacity', 'full' => 'Full', 'over' => 'Overcapacity', 'unknown' => 'Capacity not set'][$s['tier']];
                    @endphp
                    <span class="badge {{ $tierClass }}">{{ $tierLabel }}</span>
                </div>
                <p class="shelter-card-brgy">Barangay {{ $s['barangay'] }}</p>
                <p class="shelter-card-addr">{{ $s['address'] }}</p>
                @if($s['pct'] !== null)
                    <div class="capacity-bar" role="progressbar" aria-valuenow="{{ min($s['pct'], 100) }}" aria-valuemin="0" aria-valuemax="100" aria-label="Occupancy">
                        <div class="capacity-bar-fill {{ $s['tier'] === 'ok' ? '' : ($s['tier'] === 'warn' ? 'cap-warn' : 'cap-full') }}" style="width: {{ min($s['pct'], 100) }}%"></div>
                    </div>
                    <p class="kpi-note" data-numeric>{{ $s['occupancy'] }} / {{ $s['capacity'] }} ({{ $s['pct'] }}%)</p>
                @endif
                <div class="shelter-card-contact">
                    @if($s['contact_number'])
                        <p><span class="contact-label">Phone:</span> <a href="tel:{{ preg_replace('/[^0-9+]/', '', $s['contact_number']) }}" data-numeric>{{ $s['contact_number'] }}</a></p>
                    @endif
                    @if($s['contact_email'])
                        <p><span class="contact-label">Email:</span> <a href="mailto:{{ $s['contact_email'] }}">{{ $s['contact_email'] }}</a></p>
                    @endif
                    @if(! $s['contact_number'] && ! $s['contact_email'])
                        <p class="text-muted">Contact the CDRRMO hotline (see Emergency Hotlines).</p>
                    @endif
                    @if($s['lat'] === null)
                        <p class="text-muted">Map location not yet available for this shelter.</p>
                    @endif
                </div>
            </article>
        @empty
            <p class="empty-note">No active evacuation shelters are listed right now. In an emergency, call the CDRRMO hotline.</p>
        @endforelse
    </div>
</section>
@endsection

@push('scripts')
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
<script>
(function () {
    const shelters = @json($shelters);
    const CABUYAO = [14.2726, 121.1262]; // city center fallback

    const map = L.map('shelterMap').setView(CABUYAO, 13);
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);

    const tierColors = { ok: '#15803D', warn: '#B45309', full: '#B91C1C', over: '#7f1d1d', unknown: '#64748B' };

    const pinned = shelters.filter(s => s.lat !== null && s.lng !== null);
    pinned.forEach(s => {
        const marker = L.circleMarker([s.lat, s.lng], {
            radius: 10,
            color: '#fff',
            weight: 2,
            fillColor: tierColors[s.tier] || tierColors.unknown,
            fillOpacity: 0.95
        }).addTo(map);

        const occ = s.pct !== null ? `${s.occupancy} / ${s.capacity} (${s.pct}%)` : 'Capacity not set';
        const phone = s.contact_number ? `<br>Phone: ${s.contact_number}` : '';
        marker.bindPopup(`<strong>${s.name}</strong><br>Barangay ${s.barangay}<br>${s.address}<br>Occupancy: ${occ}${phone}`);
    });

    if (pinned.length > 0) {
        map.fitBounds(pinned.map(s => [s.lat, s.lng]), { padding: [40, 40], maxZoom: 14 });
    }

    // ---- Geolocation: nearest shelters ----
    const locateBtn = document.getElementById('locateBtn');
    const hint = document.getElementById('locateHint');
    const panel = document.getElementById('nearestPanel');
    const list = document.getElementById('nearestList');
    let userMarker = null;

    function haversineKm(lat1, lng1, lat2, lng2) {
        const R = 6371, rad = Math.PI / 180;
        const dLat = (lat2 - lat1) * rad, dLng = (lng2 - lng1) * rad;
        const a = Math.sin(dLat / 2) ** 2 +
                  Math.cos(lat1 * rad) * Math.cos(lat2 * rad) * Math.sin(dLng / 2) ** 2;
        return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    }

    locateBtn.addEventListener('click', () => {
        if (!navigator.geolocation) {
            hint.textContent = 'Location is not supported by this browser.';
            return;
        }
        hint.textContent = 'Locating...';
        navigator.geolocation.getCurrentPosition(pos => {
            const { latitude: lat, longitude: lng } = pos.coords;
            hint.textContent = '';

            if (userMarker) map.removeLayer(userMarker);
            userMarker = L.marker([lat, lng], { title: 'Your location' }).addTo(map)
                .bindPopup('You are here').openPopup();
            map.setView([lat, lng], 14);

            const nearest = pinned
                .map(s => ({ ...s, km: haversineKm(lat, lng, s.lat, s.lng) }))
                .sort((a, b) => a.km - b.km)
                .slice(0, 5);

            list.innerHTML = '';
            nearest.forEach(s => {
                const li = document.createElement('li');
                li.innerHTML = `<strong>${s.name}</strong><br><span class="text-muted">${s.address}</span><br><span data-numeric>${s.km.toFixed(1)} km away</span>`;
                li.addEventListener('click', () => { map.setView([s.lat, s.lng], 16); });
                list.appendChild(li);
            });
            panel.hidden = nearest.length === 0;
        }, err => {
            hint.textContent = err.code === err.PERMISSION_DENIED
                ? 'Location permission was denied. Showing the city-wide map instead.'
                : 'Could not determine your location. Showing the city-wide map instead.';
        }, { enableHighAccuracy: true, timeout: 10000 });
    });
})();
</script>
@endpush
