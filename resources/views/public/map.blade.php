@extends('layouts.public')

@section('title', 'Evacuation Map')

@php
    // Opts this page into the bundled Leaflet entry (roadmap item 3). The
    // cdnjs <link> and <script> tags are gone: the app must work with no
    // internet connection. Set here, read by the single @vite() call in
    // layouts/public.blade.php.
    $viteEntries = ['resources/js/map.js'];

    // Capacity tier -> presentation, defined ONCE and used by both the cards
    // below and the JSON island the map reads. It used to be declared inline in
    // the card loop and again as a colour table inside the page's <script>,
    // which is how a legend drifts out of step with its markers.
    $tierBadge = [
        'ok' => 'badge-success',
        'warn' => 'badge-warning',
        'full' => 'badge-danger',
        'over' => 'badge-over',
        'unknown' => 'badge-info',
    ];
    $tierLabels = [
        'ok' => 'Space available',
        'warn' => 'Nearing capacity',
        'full' => 'Full',
        'over' => 'Overcapacity',
        'unknown' => 'Capacity not set',
    ];

    // The map's payload. tier_label is added so the marker popups and the
    // nearest-shelter list can state capacity in words -- colour alone never
    // carries status in this system.
    $shelterData = collect($shelters)->map(function ($s) use ($tierLabels) {
        $s['tier_label'] = $tierLabels[$s['tier']] ?? $tierLabels['unknown'];
        return $s;
    })->values();

    // JSON_HEX_* is what makes the {!! !!} below safe: `<` and `>` become \u003C
    // and \u003E, so a shelter name or address cannot close the script element
    // early. These are the same flags Blade's @json uses. Plain {{ }} would
    // HTML-escape the quotes and produce unparseable JSON.
    $shelterJson = json_encode($shelterData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
@endphp

@section('content')
<section class="mb-6">
    <h1 class="mb-2 text-xl md:text-2xl">Evacuation Centers</h1>
    <p class="m-0 max-w-[640px] text-ink-soft">
        Find active evacuation shelters across the City of Cabuyao. Allow location access to see the shelters nearest to you.
    </p>
</section>

<section>
    <div class="mb-3 flex flex-wrap items-center gap-3">
        <button type="button" class="btn-primary w-full sm:w-auto" id="locateBtn">Find shelters near me</button>
        <span class="text-sm text-ink-soft" id="locateHint" role="status"></span>
    </div>

    {{-- Legend. Each swatch is paired with its wording, so the map is readable
         without relying on colour discrimination. --}}
    <ul class="mb-3 flex list-none flex-wrap gap-x-4 gap-y-2 p-0 text-sm text-ink-soft">
        <li class="flex items-center gap-2"><span class="inline-block h-3 w-3 rounded-full bg-success" aria-hidden="true"></span>Space available</li>
        <li class="flex items-center gap-2"><span class="inline-block h-3 w-3 rounded-full bg-warning" aria-hidden="true"></span>Nearing capacity</li>
        <li class="flex items-center gap-2"><span class="inline-block h-3 w-3 rounded-full bg-danger" aria-hidden="true"></span>Full</li>
        <li class="flex items-center gap-2"><span class="inline-block h-3 w-3 rounded-full bg-over" aria-hidden="true"></span>Overcapacity</li>
    </ul>

    <div class="grid grid-cols-1 items-start gap-4 lg:grid-cols-[1fr_300px]">
        <div>
            {{-- Leaflet needs a definite height on its container or it renders
                 nothing at all. z-[1] keeps tile panes under the sticky header. --}}
            <div id="shelterMap" class="h-[320px] rounded-lg border border-border shadow-sm z-[1] lg:h-[480px]"
                 role="application" aria-label="Map of evacuation shelters"></div>

            {{-- Map tiles are still fetched from openstreetmap.org at runtime;
                 caching them is roadmap item 4. Until then the map fails out
                 loud, because a citizen staring at a grey rectangle needs to know
                 the MAP is unavailable, not that there are no shelters. The
                 shelter list below this point works with no connection at all. --}}
            <p class="map-offline-note" id="mapOfflineNote" role="status" hidden>
                <span aria-hidden="true">&#9888;</span>
                Map tiles could not be loaded, so the map may appear blank. This needs an internet connection. The full list of shelters below still works, including phone numbers.
            </p>
        </div>

        <aside class="card p-4 lg:max-h-[480px] lg:overflow-y-auto" id="nearestPanel" hidden>
            <h2 class="panel-title">Nearest Shelters</h2>
            <ol class="m-0 list-none p-0" id="nearestList"></ol>
        </aside>
    </div>
</section>

<section class="mt-8">
    <h2 class="mb-4 text-lg sm:text-xl">All Evacuation Shelters</h2>
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
        @forelse($shelterData as $s)
            <article class="card p-4 sm:p-5">
                <div class="flex items-start justify-between gap-2">
                    <h3 class="m-0 text-lg">{{ $s['name'] }}</h3>
                    <span class="badge {{ $tierBadge[$s['tier']] }}">{{ $s['tier_label'] }}</span>
                </div>
                <p class="mt-1 mb-0 text-sm font-semibold text-primary">Barangay {{ $s['barangay'] }}</p>
                <p class="mt-1 mb-3 text-sm text-ink-soft">{{ $s['address'] }}</p>

                @if($s['pct'] !== null)
                    <div class="capacity-bar" role="progressbar" aria-valuenow="{{ min($s['pct'], 100) }}" aria-valuemin="0" aria-valuemax="100" aria-label="Occupancy">
                        <div class="capacity-bar-fill {{ $s['tier'] === 'ok' ? '' : ($s['tier'] === 'warn' ? 'cap-warn' : 'cap-full') }}" style="width: {{ min($s['pct'], 100) }}%"></div>
                    </div>
                    <p class="kpi-note" data-numeric>{{ $s['occupancy'] }} / {{ $s['capacity'] }} ({{ $s['pct'] }}%)</p>
                @endif

                <div class="mt-3 border-t border-border pt-3 text-sm">
                    @if($s['contact_number'])
                        <p class="my-1"><span class="text-ink-muted">Phone:</span>
                            <a class="inline-flex min-h-tap items-center text-primary no-underline hover:underline"
                               href="tel:{{ preg_replace('/[^0-9+]/', '', $s['contact_number']) }}" data-numeric>{{ $s['contact_number'] }}</a>
                        </p>
                    @endif
                    @if($s['contact_email'])
                        <p class="my-1"><span class="text-ink-muted">Email:</span>
                            <a class="inline-flex min-h-tap items-center text-primary no-underline hover:underline"
                               href="mailto:{{ $s['contact_email'] }}">{{ $s['contact_email'] }}</a>
                        </p>
                    @endif
                    @if(! $s['contact_number'] && ! $s['contact_email'])
                        <p class="my-1 text-ink-muted">Contact the CDRRMO hotline (see Emergency Hotlines).</p>
                    @endif
                    @if($s['lat'] === null)
                        <p class="my-1 text-ink-muted">Map location not yet available for this shelter.</p>
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
{{-- Data island, not a JS expression. The browser never parses this as code, so
     a shelter name containing a quote or a bracket is inert here. resources/js/map.js
     reads it by id. --}}
<script type="application/json" id="shelterMapData">{!! $shelterJson !!}</script>
@endpush
