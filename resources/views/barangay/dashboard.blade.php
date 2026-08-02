@extends('layouts.staff')

@section('title', 'Dashboard')
@section('page-title', 'Dashboard')
@section('page-subtitle', 'Overview of evacuation operations and shelter status.')

@php
    // Opts this page into the bundled Chart.js entry (roadmap item 3). The cdnjs
    // <script> tag is gone. Read by the single @vite() call in layouts/staff.
    $viteEntries = ['resources/js/charts.js'];

    $pct = $kpis['capacity_pct'];
    $capClass = $pct === null ? '' : ($pct > 100 ? 'cap-over' : ($pct >= 90 ? 'cap-full' : ($pct >= 70 ? 'cap-warn' : 'cap-ok')));
    $capLabel = $pct === null ? 'No capacity set' : ($pct > 100 ? 'Overcapacity' : ($pct >= 90 ? 'Full' : ($pct >= 70 ? 'Nearing capacity' : 'Space available')));

    // Chart payload for resources/js/charts.js. Built here in a @php block, not
    // inline in a @json expression: the gotcha list is explicit that @json with
    // => arrows or across lines fails to parse.
    $chartPayload = [
        'labels' => $chart['labels'],
        'datasets' => [[
            'label' => 'Households registered',
            'data' => $chart['data'],
            'colorToken' => '--color-primary-400',
        ]],
    ];
    $chartJson = json_encode($chartPayload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

    // ---- PHASE 3 ITEM 9: age-group and vulnerable-category charts ----
    // Same rule as above: arrays are built here, never inline in a Blade json
    // directive containing arrows or spanning several lines.
    //
    // AGE: a doughnut is legitimate here because the seven tiers are mutually
    // exclusive and sum to the headcount, so parts-of-a-whole is a true claim.
    // The Unknown bucket is charted only when it holds someone -- registration
    // has required a birthdate or an age group since Phase 2, so a populated
    // Unknown can only come from an older row, and an empty slice would be
    // noise on every dashboard in the city.
    // $ageRows is prepared by the controller via AgeTier::chartRows(): short
    // labels, and the Unknown bucket already dropped when it holds nobody.
    $agePayload = [
        'labels' => array_column($ageRows, 'label'),
        'datasets' => [[
            'label' => 'Persons',
            'data' => array_map(fn ($r) => (int) $r['total'], $ageRows),
        ]],
    ];

    // CATEGORIES: a BAR, never a pie. These categories overlap -- one person can
    // be a pregnant solo parent on 4Ps -- so a pie would assert a whole that
    // does not exist. IdpForm refuses to print a column total for the same
    // reason. Rows come from IdpForm so the chart and the signed CSWDO form are
    // the same figures by construction.
    $categoryPayload = [
        'labels' => array_column($categoryRows, 'label'),
        'datasets' => [[
            'label' => 'Persons',
            'data' => array_map(fn ($r) => (int) $r['total'], $categoryRows),
            'colorToken' => '--color-primary-600',
        ]],
    ];

    $ageJson = json_encode($agePayload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $categoryJson = json_encode($categoryPayload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
@endphp

@section('page-actions')
    {{-- This used to read "Live Updates Active" beside a pulsing green dot.
         Nothing on this page polls -- there is no setInterval, no EventSource,
         no websocket anywhere in the codebase -- so the badge was telling staff
         that an occupancy figure refreshes itself when it does not. During a
         flood that is the kind of reassurance that gets acted on.

         It now reports what the browser actually knows: whether there is a
         connection, and when this page was loaded. Wired in staff.js. --}}
    <span class="sync-pill" id="connectivityPill" data-conn="online"
          data-rendered-at="{{ now()->format('g:i A') }}"
          role="status" aria-live="polite"
          title="Figures on this page were loaded at {{ now()->format('g:i A') }}. Reload to refresh them.">
        <span class="sync-dot" aria-hidden="true"></span>
        <span id="connectivityText">Connected</span>
    </span>
@endsection

@section('content')
@unless($center)
    <div class="alert alert-warning" role="alert">
        No shelter assigned to this account. Ask your Evacuation Administrator to assign you to a shelter &mdash; the numbers below will stay at zero until then.
    </div>
@endunless

{{-- Five KPI cards. One column on a phone, two from 640px, then three and five.
     Five across only above 1280px: below that a fifth column squeezes the
     numbers, and these are the figures the whole screen exists to show. --}}
<section class="mb-4 grid grid-cols-1 items-stretch gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5" aria-label="Key metrics">
    <article class="card kpi-card">
        <div class="kpi-head">
            <span class="kpi-icon-wrap" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="7" r="3"/><path d="M2.5 20c0-3.3 2.9-6 6.5-6s6.5 2.7 6.5 6"/><circle cx="17" cy="7.5" r="2.3"/><path d="M15.5 12c2.3 0 4.5 1.6 5 4"/></svg>
            </span>
            <span class="kpi-title">Checked-in Individuals</span>
        </div>
        <p class="kpi-value" data-numeric>{{ number_format($kpis['individuals']) }}</p>
        <p class="kpi-note">Total persons currently sheltered</p>
    </article>

    <article class="card kpi-card">
        <div class="kpi-head">
            <span class="kpi-icon-wrap" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 11.5 12 4l9 7.5"/><path d="M5.5 10v9a1 1 0 0 0 1 1H9v-6h6v6h2.5a1 1 0 0 0 1-1v-9"/></svg>
            </span>
            <span class="kpi-title">Families Sheltered</span>
        </div>
        <p class="kpi-value" data-numeric>{{ number_format($kpis['households']) }}</p>
        <p class="kpi-note">Households currently checked in</p>
    </article>

    <article class="card kpi-card">
        <div class="kpi-head">
            <span class="kpi-icon-wrap" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 12V4a8 8 0 0 1 8 8h-8z"/></svg>
            </span>
            <span class="kpi-title">Center Capacity</span>
        </div>
        <p class="kpi-value {{ $capClass }}" data-numeric>{{ $pct !== null ? $pct . '%' : '-' }}</p>
        <p class="kpi-note">
            <span class="{{ $capClass }}">{{ $capLabel }}</span>
            @if($center) &middot; {{ number_format($kpis['occupancy']) }} / {{ number_format($kpis['capacity']) }} &middot; {{ $center->name }} @endif
        </p>
    </article>

    <article class="card kpi-card">
        <div class="kpi-head">
            <span class="kpi-icon-wrap" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8 12 3 3 8l9 5 9-5z"/><path d="M3 8v8l9 5 9-5V8"/><path d="M12 13v8"/></svg>
            </span>
            <span class="kpi-title">Relief Packs Available</span>
        </div>
        <p class="kpi-value {{ $kpis['low_stock'] ? 'cap-warn' : '' }}" data-numeric>{{ number_format($kpis['relief_packs']) }}</p>
        <p class="kpi-note">{{ $kpis['low_stock'] ? '&#9888; Some items at or below reorder level' : 'Ready for distribution' }}</p>
    </article>

    <article class="card kpi-card">
        <div class="kpi-head">
            <span class="kpi-icon-wrap" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2 4 5.5v5c0 5 3.2 8.7 8 10.5 4.8-1.8 8-5.5 8-10.5v-5L12 2z"/><path d="M9 12l2 2 4-4"/></svg>
            </span>
            <span class="kpi-title">Vulnerable Individuals</span>
        </div>
        <p class="kpi-value" data-numeric>{{ number_format($kpis['vulnerable']) }}</p>
        <p class="kpi-note">Checked-in persons with vulnerability tags</p>
    </article>
</section>

<section class="grid grid-cols-1 items-start gap-4 lg:grid-cols-3">
    <article class="card panel lg:col-span-2">
        <h2 class="panel-title">Daily Registrations (Past 7 Days)</h2>
        {{-- .chart-wrap owns the height (240px, 300px from 768px) and the canvas
             carries NO height attribute. Chart.js sizes from its parent, and a
             height attribute fights the wrapper into either a zero-height or an
             unbounded canvas. maintainAspectRatio is false in charts.js. --}}
        <div class="chart-wrap">
            <canvas id="registrationsChart"
                    data-chart="bar"
                    data-chart-data="registrationsChartData"
                    role="img"
                    aria-label="Bar chart of daily household registrations for the past 7 days"></canvas>
        </div>
        {{-- The chart is an image to a screen reader, so the same figures are
             available as text. Also the fallback when JS or the bundle fails. --}}
        <details class="mt-3 text-sm text-ink-soft">
            <summary class="min-h-tap cursor-pointer py-2">View these figures as a table</summary>
            <table class="data-table mt-2">
                <caption class="visually-hidden">Households registered per day, past 7 days</caption>
                <thead><tr><th scope="col">Day</th><th scope="col">Households registered</th></tr></thead>
                <tbody>
                    @foreach($chart['labels'] as $i => $label)
                        <tr>
                            <td>{{ $label }}</td>
                            <td data-numeric>{{ $chart['data'][$i] ?? 0 }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </details>
    </article>

    <div class="flex flex-col">
        <h2 class="panel-title">Quick Actions</h2>
        <a class="card action-card" href="{{ route('barangay.evacuees.index') }}?open=register">
            <span class="action-icon" aria-hidden="true">+</span>
            <span><strong>Register Evacuee</strong><br><small>Add a new family to the system</small></span>
        </a>
        <a class="card action-card" href="{{ route('barangay.shelter.index') }}?open=checkin">
            <span class="action-icon" aria-hidden="true">&check;</span>
            <span><strong>Check-in Household</strong><br><small>Existing family arriving at the center</small></span>
        </a>
        <a class="card action-card" href="{{ route('barangay.shelter.index') }}">
            <span class="action-icon" aria-hidden="true">&#8677;</span>
            <span><strong>Check-out Household</strong><br><small>Family leaving the center</small></span>
        </a>
        {{-- This icon span was EMPTY: a raw glyph had been stripped out of it at
             some point, leaving the one quick action on the page with no mark
             beside it. Now an entity, like every other icon in the codebase. --}}
        <a class="card action-card" href="{{ route('barangay.relief.index') }}?open=distribute">
            <span class="action-icon" aria-hidden="true">&#128230;</span>
            <span><strong>Distribute Relief</strong><br><small>Log goods given to families</small></span>
        </a>

        <h2 class="panel-title mt-6">Recent Activity</h2>
        <div class="card panel activity-panel">
            @forelse($recent as $event)
                {{-- Wraps rather than overflowing at 380px: the name and the time
                     are both needed, so neither may be pushed off-screen. --}}
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-border p-3 text-sm last:border-b-0">
                    <span class="badge {{ $event['type'] === 'check_in' ? 'badge-success' : 'badge-warning' }}">
                        {{ $event['type'] === 'check_in' ? 'Check-in' : 'Check-out' }}
                    </span>
                    <span class="min-w-0 flex-1 font-medium">{{ $event['household']->headMember?->full_name ?? $event['household']->household_code }}</span>
                    <time class="text-ink-muted" datetime="{{ $event['at']->toIso8601String() }}">{{ $event['at']->diffForHumans() }}</time>
                </div>
            @empty
                <p class="empty-note">No check-ins or check-outs yet. Activity will appear here as families arrive.</p>
            @endforelse
        </div>
    </div>
</section>

{{-- PHASE 3 ITEM 9. Two charts over the same checked-in, present population the
     IDP Monitoring Form reports on, so a barangay operator sees on screen what
     CSWDO will read on the printed sheet. --}}
<section class="mt-4 grid grid-cols-1 items-start gap-4 lg:grid-cols-2">
    <article class="card panel">
        <h2 class="panel-title">Age Group Distribution</h2>
        <div class="chart-wrap">
            <canvas id="ageGroupChart"
                    data-chart="doughnut"
                    data-chart-data="ageGroupChartData"
                    role="img"
                    aria-label="Doughnut chart of checked-in persons by age group"></canvas>
        </div>
        <details class="mt-3 text-sm text-ink-soft">
            <summary class="min-h-tap cursor-pointer py-2">View these figures as a table</summary>
            {{-- Four columns, so data-stack plus a data-label on every cell. This
                 table also carries the male/female split, which a doughnut
                 cannot show -- nothing is lost by charting totals only. --}}
            <table class="data-table mt-2" data-stack>
                <caption class="visually-hidden">Checked-in persons by age group and sex</caption>
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

    <article class="card panel">
        <h2 class="panel-title">Vulnerable Categories</h2>
        {{-- A BAR, not a pie. These categories overlap -- one person can be a
             pregnant solo parent on 4Ps -- so parts-of-a-whole would be a claim
             the data does not support. Single-Headed is a household with exactly
             one person present, so its bar counts the same way the others do. --}}
        <div class="chart-wrap">
            <canvas id="categoryChart"
                    data-chart="bar"
                    data-chart-data="categoryChartData"
                    role="img"
                    aria-label="Bar chart of checked-in persons by vulnerable category"></canvas>
        </div>
        <details class="mt-3 text-sm text-ink-soft">
            <summary class="min-h-tap cursor-pointer py-2">View these figures as a table</summary>
            <table class="data-table mt-2" data-stack>
                <caption class="visually-hidden">Checked-in persons by vulnerable category and sex</caption>
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
                        <tr><td colspan="4" class="empty-note">No one is checked in yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </details>
        <p class="field-hint">
            A person can belong to several categories at once, so these figures
            deliberately do not add up to a total.
        </p>
    </article>
</section>
@endsection

@push('scripts')
{{-- Data island read by resources/js/charts.js. Inert to the HTML parser. --}}
<script type="application/json" id="registrationsChartData">{!! $chartJson !!}</script>
<script type="application/json" id="ageGroupChartData">{!! $ageJson !!}</script>
<script type="application/json" id="categoryChartData">{!! $categoryJson !!}</script>
@endpush
