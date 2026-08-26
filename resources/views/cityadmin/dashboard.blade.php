@extends('layouts.cityadmin')

@section('title', 'Dashboard')
@section('page-title', 'Dashboard')
@section('page-subtitle', 'City-wide overview of evacuation operations and shelter status.')

@php
    // Opts this page into the bundled Chart.js entry (roadmap item 3). This
    // replaces the cdnjs <script> tag that used to sit in the scripts stack --
    // it was the LAST CDN reference in the codebase, so with it gone EvacTech
    // no longer needs an internet connection to render any page.
    //
    // Requires the array_merge @vite() line in layouts/cityadmin.
    $viteEntries = ['resources/js/charts.js'];

    // GOTCHA #1: chart payloads are built here and emitted with json_encode()
    // below. Never inline a Blade json directive containing arrows or spanning
    // multiple lines -- it fails to parse.
    $topBarangaysPayload = [
        'labels' => $topBarangays->pluck('name')->values()->all(),
        'datasets' => [[
            'label' => 'Evacuees',
            'data' => $topBarangays->pluck('total')->values()->all(),
            'colorToken' => '--color-primary-400',
        ]],
    ];

    // PHASE 3 ITEM 9. Was $vulnerableGroups, keyed on classification NAME and
    // drawn as a doughnut. Now IdpForm::categoriesFor(), keyed on code, drawn as
    // a BAR: these categories overlap, so parts-of-a-whole was a claim the data
    // never supported. City Admin's list carries a seventh bar, Chronic Illness,
    // which is a live internal medical-desk tag and deliberately off the CSWDO
    // form -- the barangay chart and the form both stop at six.
    $categoryPayload = [
        'labels' => array_column($categoryRows, 'label'),
        'datasets' => [[
            'label' => 'Persons',
            'data' => array_map(fn ($r) => (int) $r['total'], $categoryRows),
            'colorToken' => '--color-primary-600',
        ]],
    ];

    // Age tiers ARE mutually exclusive and do sum to the headcount, so a
    // doughnut is honest here. Unknown is charted only when it holds someone:
    // registration has required a birthdate or an age group since Phase 2, so a
    // populated Unknown can only come from an older row.
    // $ageRows is prepared by the controller via AgeTier::chartRows(): short
    // labels, and the Unknown bucket already dropped when it holds nobody.
    $agePayload = [
        'labels' => array_column($ageRows, 'label'),
        'datasets' => [[
            'label' => 'Persons',
            'data' => array_map(fn ($r) => (int) $r['total'], $ageRows),
        ]],
    ];

    // The HEX flags are what make it safe to emit these with {!! !!} into a
    // <script type="application/json"> island: a barangay or classification name
    // containing a quote or an angle bracket cannot close the tag early.
    $flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
    $topBarangaysJson = json_encode($topBarangaysPayload, $flags);
    $categoryJson = json_encode($categoryPayload, $flags);
    $ageJson = json_encode($agePayload, $flags);
@endphp

@section('page-actions')
    {{-- This read "Live Updates Active" beside a pulsing green dot. Nothing on
         this page polls -- there is no setInterval, no EventSource and no
         websocket anywhere in the codebase -- so it was telling CDRRMO staff
         that a city-wide occupancy figure refreshes itself when it does not.
         During a flood that is the kind of reassurance that gets acted on.

         Replaced with the same honest indicator the barangay dashboard uses:
         what the browser actually knows, plus when this page was rendered.
         Wired by initConnectivity() in staff.js. --}}
    <span class="sync-pill" id="connectivityPill" data-conn="online"
          data-rendered-at="{{ now()->format('g:i A') }}"
          role="status" aria-live="polite"
          title="Figures on this page were loaded at {{ now()->format('g:i A') }}. Reload to refresh them.">
        <span class="sync-dot" aria-hidden="true"></span>
        <span id="connectivityText">Connected</span>
    </span>
@endsection

@section('content')
{{-- Five KPI cards. One column on a phone, two from 640px, then three and five.
     Five across only above 1280px: below that a fifth column squeezes the
     numbers, and these are the figures the whole screen exists to show. --}}
<section class="mb-4 grid grid-cols-1 items-stretch gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5" aria-label="Key metrics">
    <article class="card kpi-card">
        <div class="kpi-head"><span class="kpi-icon-wrap" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="7" r="3"/><path d="M2.5 20c0-3.3 2.9-6 6.5-6s6.5 2.7 6.5 6"/><circle cx="17" cy="7.5" r="2.3"/><path d="M15.5 12c2.3 0 4.5 1.6 5 4"/></svg></span><span class="kpi-title">Total Evacuees</span></div>
        <p class="kpi-value" data-numeric>{{ number_format($kpis['total_evacuees']) }}</p>
        <p class="kpi-note">Checked-in persons across all shelters</p>
    </article>
    <article class="card kpi-card">
        <div class="kpi-head"><span class="kpi-icon-wrap" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 11.5 12 4l9 7.5"/><path d="M5.5 10v9a1 1 0 0 0 1 1H9v-6h6v6h2.5a1 1 0 0 0 1-1v-9"/></svg></span><span class="kpi-title">Active Shelters</span></div>
        <p class="kpi-value" data-numeric>{{ $kpis['active_shelters'] }}</p>
        <p class="kpi-note">of {{ $kpis['total_shelters'] }} total centers</p>
    </article>
    <article class="card kpi-card">
        <div class="kpi-head"><span class="kpi-icon-wrap" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/></svg></span><span class="kpi-title">Families Registered</span></div>
        <p class="kpi-value" data-numeric>{{ number_format($kpis['families_registered']) }}</p>
        <p class="kpi-note">+{{ $kpis['families_today'] }} new today</p>
    </article>
    <article class="card kpi-card">
        <div class="kpi-head"><span class="kpi-icon-wrap" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8 12 3 3 8l9 5 9-5z"/><path d="M3 8v8l9 5 9-5V8"/></svg></span><span class="kpi-title">Relief Distributed</span></div>
        <p class="kpi-value" data-numeric>{{ number_format($kpis['relief_distributed']) }}</p>
        <p class="kpi-note">Total units given out</p>
    </article>
    <article class="card kpi-card">
        <div class="kpi-head"><span class="kpi-icon-wrap" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2 4 5.5v5c0 5 3.2 8.7 8 10.5 4.8-1.8 8-5.5 8-10.5v-5L12 2z"/><path d="M9 12l2 2 4-4"/></svg></span><span class="kpi-title">Vulnerable Individuals</span></div>
        <p class="kpi-value" data-numeric>{{ number_format($kpis['vulnerable']) }}</p>
        <p class="kpi-note">Checked-in persons with tags</p>
    </article>
</section>

<section class="grid grid-cols-1 items-start gap-4 lg:grid-cols-2">
    <article class="card panel">
        <h2 class="panel-title">Evacuees per Barangay (Top 5)</h2>
        {{-- NO height attribute on the canvas. .chart-wrap owns the height and
             maintainAspectRatio is false in charts.js; a height attribute fights
             the wrapper and collapses the chart. The old markup had
             height="220" precisely because the CDN build had no wrapper. --}}
        <div class="chart-wrap">
            <canvas id="topBarangaysChart"
                    data-chart="bar"
                    data-chart-data="topBarangaysChartData"
                    role="img"
                    aria-label="Bar chart of the top 5 barangays by evacuee count"></canvas>
        </div>
        {{-- PHASE 8 ITEM 5. Exports the canvas above as a PNG. Delegated in
             charts.js, so the id here is the only wiring. --}}
        <div class="mt-2 flex justify-end">
            <button type="button" class="btn-secondary"
                    data-chart-download="topBarangaysChart"
                    data-chart-label="Top Barangays by Evacuees">Download PNG</button>
        </div>
        {{-- The chart is an image to a screen reader, so the same figures are
             available as text -- and this is the fallback if the bundle fails. --}}
        <details class="mt-3 text-sm text-ink-soft">
            <summary class="min-h-tap cursor-pointer py-2">View these figures as a table</summary>
            <table class="data-table mt-2">
                <caption class="visually-hidden">Evacuee count by barangay, top 5</caption>
                <thead><tr><th scope="col">Barangay</th><th scope="col">Evacuees</th></tr></thead>
                <tbody>
                    @forelse($topBarangays as $b)
                        <tr><td>{{ $b->name }}</td><td data-numeric>{{ number_format($b->total) }}</td></tr>
                    @empty
                        <tr><td colspan="2" class="empty-note">No evacuees recorded yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </details>
    </article>

    <article class="card panel">
        <h2 class="panel-title">Vulnerable Categories</h2>
        {{-- A BAR, not the doughnut this used to be. These categories overlap --
             one person can be a pregnant solo parent on 4Ps -- so a doughnut
             asserted a whole that does not exist. IdpForm refuses to print a
             column total for the same reason. --}}
        <div class="chart-wrap">
            <canvas id="categoryChart"
                    data-chart="bar"
                    data-chart-data="categoryChartData"
                    role="img"
                    aria-label="Bar chart of checked-in persons by vulnerable category, city-wide"></canvas>
        </div>
        {{-- PHASE 8 ITEM 5. Exports the canvas above as a PNG. Delegated in
             charts.js, so the id here is the only wiring. --}}
        <div class="mt-2 flex justify-end">
            <button type="button" class="btn-secondary"
                    data-chart-download="categoryChart"
                    data-chart-label="Vulnerable Categories">Download PNG</button>
        </div>
        <details class="mt-3 text-sm text-ink-soft">
            <summary class="min-h-tap cursor-pointer py-2">View these figures as a table</summary>
            <table class="data-table mt-2" data-stack>
                <caption class="visually-hidden">Checked-in persons by vulnerable category and sex, city-wide</caption>
                <thead>
                    <tr>
                        <th scope="col">Category</th>
                        <th scope="col">Male</th>
                        <th scope="col">Female</th>
                        <th scope="col">Total</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($categoryRows as $row)
                        <tr>
                            <td data-label="Category">{{ $row['label'] }}</td>
                            <td data-label="Male" data-numeric>{{ number_format($row['male']) }}</td>
                            <td data-label="Female" data-numeric>{{ number_format($row['female']) }}</td>
                            <td data-label="Total" data-numeric>{{ number_format($row['total']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="empty-note">No vulnerability tags recorded yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </details>
        <p class="field-hint">
            A person can belong to several categories at once, so these figures
            deliberately do not add up to a total. Chronic Illness is an internal
            operational tag and does not appear on the CSWDO IDP form.
        </p>
    </article>
</section>

{{-- PHASE 3 ITEM 9. The city-wide age breakdown that feeds table 1 of the IDP
     Monitoring Form, rendered for the first time. --}}
<section class="mt-4 grid grid-cols-1 items-start gap-4 lg:grid-cols-2">
    <article class="card panel">
        <h2 class="panel-title">Age Group Distribution</h2>
        <div class="chart-wrap">
            <canvas id="ageGroupChart"
                    data-chart="doughnut"
                    data-chart-data="ageGroupChartData"
                    role="img"
                    aria-label="Doughnut chart of checked-in persons by age group, city-wide"></canvas>
        </div>
        {{-- PHASE 8 ITEM 5. Exports the canvas above as a PNG. Delegated in
             charts.js, so the id here is the only wiring. --}}
        <div class="mt-2 flex justify-end">
            <button type="button" class="btn-secondary"
                    data-chart-download="ageGroupChart"
                    data-chart-label="Age Group Distribution">Download PNG</button>
        </div>
        <details class="mt-3 text-sm text-ink-soft">
            <summary class="min-h-tap cursor-pointer py-2">View these figures as a table</summary>
            <table class="data-table mt-2" data-stack>
                <caption class="visually-hidden">Checked-in persons by age group and sex, city-wide</caption>
                <thead>
                    <tr>
                        <th scope="col">Age group</th>
                        <th scope="col">Male</th>
                        <th scope="col">Female</th>
                        <th scope="col">Total</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($ageRows as $row)
                        <tr>
                            <td data-label="Age group">{{ $row['label'] }}</td>
                            <td data-label="Male" data-numeric>{{ number_format($row['male']) }}</td>
                            <td data-label="Female" data-numeric>{{ number_format($row['female']) }}</td>
                            <td data-label="Total" data-numeric>{{ number_format($row['total']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="empty-note">No one is checked in yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </details>
    </article>
</section>

{{-- Heat map 1: Disaster risk by barangay --}}
<article class="card panel mt-4">
    <div class="heatmap-head">
        <h2 class="panel-title">Disaster Risk Heat Map (by Barangay)</h2>
        <div class="legend">
            <span><i class="dot risk-high"></i> High</span>
            <span><i class="dot risk-moderate"></i> Moderate</span>
            <span><i class="dot risk-low"></i> Low</span>
        </div>
    </div>
    <div class="heat-grid">
        @foreach($riskHeatmap as $b)
            {{-- The raw em dash here was one of 22 non-ASCII glyphs still live in
                 the City and Super Admin views. Entities only: a raw glyph is
                 what produced the mojibake in this codebase before. --}}
            <div class="heat-cell risk-{{ $b['risk'] }}" title="{{ $b['name'] }} &mdash; {{ ucfirst($b['risk']) }} risk">
                <span class="heat-cell-name">{{ $b['name'] }}</span>
            </div>
        @endforeach
    </div>
</article>

{{-- Heat map 2: Shelter status / occupancy --}}
<article class="card panel mt-4">
    <div class="heatmap-head">
        <h2 class="panel-title">Shelter Status Heat Map</h2>
        <div class="legend">
            <span><i class="dot cap-ok-bg"></i> &lt;70%</span>
            <span><i class="dot cap-warn-bg"></i> 70&ndash;89%</span>
            <span><i class="dot cap-full-bg"></i> 90&ndash;100%</span>
            <span><i class="dot cap-over-bg"></i> Over</span>
            <span><i class="dot tier-inactive"></i> Inactive</span>
        </div>
    </div>
    <div class="heat-grid">
        @forelse($shelterHeatmap as $s)
            <div class="heat-cell tier-{{ $s['tier'] }}" title="{{ $s['name'] }} &mdash; {{ $s['occupancy'] }}/{{ $s['capacity'] }}{{ $s['pct'] !== null ? ' (' . $s['pct'] . '%)' : '' }} &middot; {{ ucfirst($s['status']) }}">
                <span class="heat-cell-name">{{ $s['name'] }}</span>
                {{-- An entity cannot go inside {{ }}: Blade's e() double-encodes
                     it and "&mdash;" renders as literal text. The branch keeps
                     the entity in raw HTML where the parser will decode it. --}}
                <span class="heat-cell-sub">@if($s['pct'] !== null){{ $s['pct'] }}%@else&mdash;@endif</span>
            </div>
        @empty
            <p class="empty-note">No shelters registered yet.</p>
        @endforelse
    </div>
</article>

{{-- Heat map 3: Relief stock per shelter (matrix) --}}
<article class="card panel mt-4">
    <div class="heatmap-head">
        <h2 class="panel-title">Relief Stock Heat Map (Shelter &times; Good)</h2>
        <div class="legend">
            <span><i class="dot stock-none"></i> None</span>
            <span><i class="dot stock-low"></i> Low</span>
            <span><i class="dot stock-med"></i> Medium</span>
            <span><i class="dot stock-high"></i> Good</span>
        </div>
    </div>
    {{-- DELIBERATELY NOT data-stack. This is the one table in the City Admin
         screens that must keep scrolling horizontally.

         Stacking prints each cell's own heading and turns a row into a card,
         which works because a normal row is a list of facts about one thing. A
         matrix is not: the meaning of a cell is its position in BOTH axes, and
         a stacked card destroys the shelter-to-shelter comparison that is the
         only reason to draw a matrix. Twelve goods would also become twelve
         labelled lines per shelter.

         .table-panel already sets overflow-x: auto, so the inline
         style="overflow-x:auto" that used to be here was redundant. --}}
    <div class="table-panel">
        <table class="data-table heat-matrix">
            <caption class="visually-hidden">Relief stock per shelter and good. Scrolls horizontally.</caption>
            <thead>
                <tr>
                    <th scope="col">Shelter</th>
                    @foreach($goods as $g)<th scope="col" class="matrix-good">{{ $g->name }}</th>@endforeach
                </tr>
            </thead>
            <tbody>
                @forelse($reliefHeatmap as $row)
                    <tr>
                        <td>{{ $row['center'] }}</td>
                        @foreach($row['cells'] as $cell)
                            <td class="matrix-cell stock-{{ $cell['tier'] }}" title="{{ $cell['good'] }}: {{ $cell['qty'] }}">{{ $cell['qty'] }}</td>
                        @endforeach
                    </tr>
                @empty
                    <tr><td colspan="{{ $goods->count() + 1 }}" class="empty-note">No shelters registered yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</article>

<article class="card panel mt-4">
    <h2 class="panel-title">Recent Activity</h2>
    <div class="activity-panel">
        @forelse($recent as $log)
            {{-- Wraps rather than overflowing at 380px: the action, the user and
                 the time are all needed, so none may be pushed off-screen. --}}
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-border p-3 text-sm last:border-b-0">
                <span class="min-w-0 flex-1 font-medium">{{ $log->description ?? ucfirst($log->action) }}</span>
                <span class="text-ink-muted">{{ $log->user?->name }}</span>
                <time class="text-ink-muted">{{ $log->created_at?->diffForHumans() }}</time>
            </div>
        @empty
            <p class="empty-note">No recent activity.</p>
        @endforelse
    </div>
</article>
@endsection

@push('scripts')
{{-- Data islands read by resources/js/charts.js. Inert to the HTML parser, so
     nothing in the data can break the page or inject script. --}}
<script type="application/json" id="topBarangaysChartData">{!! $topBarangaysJson !!}</script>
<script type="application/json" id="categoryChartData">{!! $categoryJson !!}</script>
<script type="application/json" id="ageGroupChartData">{!! $ageJson !!}</script>
@endpush
