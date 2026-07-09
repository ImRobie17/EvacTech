@extends('layouts.staff')

@section('title', 'Dashboard')
@section('page-title', 'Dashboard')
@section('page-subtitle', 'Overview of evacuation operations and shelter status.')

@section('page-actions')
    <span class="sync-pill"><span class="sync-dot" aria-hidden="true"></span> Live Updates Active</span>
@endsection

@section('content')
@php
    $pct = $kpis['capacity_pct'];
    $capClass = $pct === null ? '' : ($pct > 100 ? 'cap-over' : ($pct >= 90 ? 'cap-full' : ($pct >= 70 ? 'cap-warn' : 'cap-ok')));
    $capLabel = $pct === null ? 'No capacity set' : ($pct > 100 ? 'Overcapacity' : ($pct >= 90 ? 'Full' : ($pct >= 70 ? 'Nearing capacity' : 'Space available')));
@endphp

@unless($center)
    <div class="alert alert-warning" role="alert">
        No evacuation center is registered for your barangay yet. Ask the City Admin to add one — the numbers below will stay at zero until then.
    </div>
@endunless

<section class="kpi-grid" aria-label="Key metrics">
    <article class="card kpi-card">
        <div class="kpi-head"><span class="kpi-icon" aria-hidden="true">👤</span><span class="kpi-title">Checked-in Individuals</span></div>
        <p class="kpi-value" data-numeric>{{ number_format($kpis['individuals']) }}</p>
        <p class="kpi-note">Total persons currently sheltered</p>
    </article>

    <article class="card kpi-card">
        <div class="kpi-head"><span class="kpi-icon" aria-hidden="true">🏠</span><span class="kpi-title">Families Sheltered</span></div>
        <p class="kpi-value" data-numeric>{{ number_format($kpis['households']) }}</p>
        <p class="kpi-note">Households currently checked in</p>
    </article>

    <article class="card kpi-card">
        <div class="kpi-head"><span class="kpi-icon" aria-hidden="true">◔</span><span class="kpi-title">Center Capacity</span></div>
        <p class="kpi-value {{ $capClass }}" data-numeric>{{ $pct !== null ? $pct . '%' : '—' }}</p>
        <p class="kpi-note">
            <span class="{{ $capClass }}">{{ $capLabel }}</span>
            @if($center) · {{ number_format($kpis['occupancy']) }} / {{ number_format($kpis['capacity']) }} · {{ $center->name }} @endif
        </p>
    </article>

    <article class="card kpi-card">
        <div class="kpi-head"><span class="kpi-icon" aria-hidden="true">📦</span><span class="kpi-title">Relief Packs Available</span></div>
        <p class="kpi-value {{ $kpis['low_stock'] ? 'cap-warn' : '' }}" data-numeric>{{ number_format($kpis['relief_packs']) }}</p>
        <p class="kpi-note">{{ $kpis['low_stock'] ? '⚠ Some items at or below reorder level' : 'Ready for distribution' }}</p>
    </article>

    <article class="card kpi-card">
        <div class="kpi-head"><span class="kpi-icon" aria-hidden="true">♿</span><span class="kpi-title">Vulnerable Individuals</span></div>
        <p class="kpi-value" data-numeric>{{ number_format($kpis['vulnerable']) }}</p>
        <p class="kpi-note">Checked-in persons with vulnerability tags</p>
    </article>
</section>

<section class="dash-columns">
    <article class="card panel">
        <h2 class="panel-title">Daily Registrations (Past 7 Days)</h2>
        <canvas id="registrationsChart" height="240" role="img" aria-label="Bar chart of daily household registrations for the past 7 days"></canvas>
    </article>

    <div class="dash-side">
        <h2 class="panel-title">Quick Actions</h2>
        <a class="card action-card" href="{{ route('barangay.evacuees.index') }}?open=register">
            <span class="action-icon" aria-hidden="true">➕</span>
            <span><strong>Register Evacuee</strong><br><small>Add a new family to the system</small></span>
        </a>
        <a class="card action-card" href="{{ route('barangay.shelter.index') }}?open=checkin">
            <span class="action-icon" aria-hidden="true">✓</span>
            <span><strong>Check-in Household</strong><br><small>Existing family arriving at the center</small></span>
        </a>
        <a class="card action-card" href="{{ route('barangay.shelter.index') }}">
            <span class="action-icon" aria-hidden="true">⇥</span>
            <span><strong>Check-out Household</strong><br><small>Family leaving the center</small></span>
        </a>
        <a class="card action-card" href="{{ route('barangay.relief.index') }}?open=distribute">
            <span class="action-icon" aria-hidden="true">📦</span>
            <span><strong>Distribute Relief</strong><br><small>Log goods given to families</small></span>
        </a>

        <h2 class="panel-title" style="margin-top: var(--space-6);">Recent Activity</h2>
        <div class="card panel activity-panel">
            @forelse($recent as $event)
                <div class="activity-row">
                    <span class="badge {{ $event['type'] === 'check_in' ? 'badge-success' : 'badge-warning' }}">
                        {{ $event['type'] === 'check_in' ? 'Check-in' : 'Check-out' }}
                    </span>
                    <span class="activity-name">{{ $event['household']->headMember?->full_name ?? $event['household']->household_code }}</span>
                    <time class="activity-time" datetime="{{ $event['at']->toIso8601String() }}">{{ $event['at']->diffForHumans() }}</time>
                </div>
            @empty
                <p class="empty-note">No check-ins or check-outs yet. Activity will appear here as families arrive.</p>
            @endforelse
        </div>
    </div>
</section>
@endsection

@push('scripts')
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
    const ctx = document.getElementById('registrationsChart');
    const styles = getComputedStyle(document.documentElement);
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: @json($chart['labels']),
            datasets: [{
                label: 'Households registered',
                data: @json($chart['data']),
                backgroundColor: styles.getPropertyValue('--color-primary-400').trim() || '#22D3EE',
                borderRadius: 6,
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
        }
    });
</script>
@endpush
