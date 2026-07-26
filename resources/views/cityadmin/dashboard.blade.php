@extends('layouts.cityadmin')

@section('title', 'Dashboard')
@section('page-title', 'Dashboard')
@section('page-subtitle', 'City-wide overview of evacuation operations and shelter status.')
@section('page-actions')
    <span class="sync-pill"><span class="sync-dot" aria-hidden="true"></span> Live Updates Active</span>
@endsection

@section('content')
<section class="kpi-grid" aria-label="Key metrics">
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

<section class="dash-columns">
    <article class="card panel">
        <h2 class="panel-title">Evacuees per Barangay (Top 5)</h2>
        <canvas id="topBarangaysChart" height="220" role="img" aria-label="Bar chart of top 5 barangays by evacuee count"></canvas>
    </article>
    <article class="card panel">
        <h2 class="panel-title">Vulnerable Groups Distribution</h2>
        <canvas id="vulnerableChart" height="220" role="img" aria-label="Donut chart of vulnerable group distribution"></canvas>
    </article>
</section>

{{-- Heat map 1: Disaster risk by barangay --}}
<article class="card panel">
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
            <div class="heat-cell risk-{{ $b['risk'] }}" title="{{ $b['name'] }} — {{ ucfirst($b['risk']) }} risk">
                <span class="heat-cell-name">{{ $b['name'] }}</span>
            </div>
        @endforeach
    </div>
</article>

{{-- Heat map 2: Shelter status / occupancy --}}
<article class="card panel">
    <div class="heatmap-head">
        <h2 class="panel-title">Shelter Status Heat Map</h2>
        <div class="legend">
            <span><i class="dot cap-ok-bg"></i> &lt;70%</span>
            <span><i class="dot cap-warn-bg"></i> 70–89%</span>
            <span><i class="dot cap-full-bg"></i> 90–100%</span>
            <span><i class="dot cap-over-bg"></i> Over</span>
            <span><i class="dot tier-inactive"></i> Inactive</span>
        </div>
    </div>
    <div class="heat-grid">
        @forelse($shelterHeatmap as $s)
            <div class="heat-cell tier-{{ $s['tier'] }}" title="{{ $s['name'] }} — {{ $s['occupancy'] }}/{{ $s['capacity'] }}{{ $s['pct'] !== null ? ' (' . $s['pct'] . '%)' : '' }} · {{ ucfirst($s['status']) }}">
                <span class="heat-cell-name">{{ $s['name'] }}</span>
                <span class="heat-cell-sub">{{ $s['pct'] !== null ? $s['pct'] . '%' : '—' }}</span>
            </div>
        @empty
            <p class="empty-note">No shelters registered yet.</p>
        @endforelse
    </div>
</article>

{{-- Heat map 3: Relief stock per shelter (matrix) --}}
<article class="card panel">
    <div class="heatmap-head">
        <h2 class="panel-title">Relief Stock Heat Map (Shelter × Good)</h2>
        <div class="legend">
            <span><i class="dot stock-none"></i> None</span>
            <span><i class="dot stock-low"></i> Low</span>
            <span><i class="dot stock-med"></i> Medium</span>
            <span><i class="dot stock-high"></i> Good</span>
        </div>
    </div>
    <div class="table-panel" style="overflow-x:auto;">
        <table class="data-table heat-matrix">
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

<article class="card panel">
    <h2 class="panel-title">Recent Activity</h2>
    <div class="activity-panel">
        @forelse($recent as $log)
            <div class="activity-row">
                <span class="activity-name">{{ $log->description ?? ucfirst($log->action) }}</span>
                <span class="text-muted">{{ $log->user?->name }}</span>
                <time class="activity-time">{{ $log->created_at?->diffForHumans() }}</time>
            </div>
        @empty
            <p class="empty-note">No recent activity.</p>
        @endforelse
    </div>
</article>
@endsection

@push('scripts')
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
    const styles = getComputedStyle(document.documentElement);
    const cyan = styles.getPropertyValue('--color-primary-700').trim() || '#0E7490';

    new Chart(document.getElementById('topBarangaysChart'), {
        type: 'bar',
        data: {
            labels: @json($topBarangays->pluck('name')),
            datasets: [{ label: 'Evacuees', data: @json($topBarangays->pluck('total')), backgroundColor: cyan, borderRadius: 6 }]
        },
        options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
    });

    new Chart(document.getElementById('vulnerableChart'), {
        type: 'doughnut',
        data: {
            labels: @json($vulnerableGroups->pluck('name')),
            datasets: [{ data: @json($vulnerableGroups->pluck('total')), backgroundColor: ['#0E7490','#22D3EE','#15803D','#B45309','#B91C1C','#6366F1'] }]
        },
        options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
    });
</script>
@endpush
