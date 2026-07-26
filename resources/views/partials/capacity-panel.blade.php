{{--
    Shelter capacity panel with progress bar. Data in, markup out -- no routes,
    no role. Thresholds come from EvacuationCenter::capacityBand() so the bands
    are defined in exactly one place.
--}}
@php
    $pct = $center->capacity > 0 ? round($center->current_occupancy / $center->capacity * 100) : null;
    $band = $center->capacityBand();
    $note = $pct === null
        ? 'Capacity not configured'
        : ($pct > 100
            ? 'Overcapacity by ' . number_format($center->overBy()) . ' - still accepting, coordinate transfers'
            : ($pct >= 90 ? 'At capacity' : ($pct >= 70 ? 'Nearing capacity' : 'Space available')));
@endphp
<article class="card panel capacity-panel">
    <h2 class="panel-title">Shelter Capacity</h2>
    <p class="capacity-figure cap-{{ $band }}" data-numeric>
        {{ number_format($center->current_occupancy) }}
        <span class="capacity-sep">/</span>
        {{ number_format($center->capacity) }}
        @if ($pct !== null)<span class="capacity-pct">({{ $pct }}%)</span>@endif
    </p>
    <div class="capacity-bar" role="progressbar" aria-valuenow="{{ $pct ?? 0 }}"
         aria-valuemin="0" aria-valuemax="100" aria-label="Shelter occupancy">
        <div class="capacity-bar-fill cap-{{ $band }}" style="width: {{ min($pct ?? 0, 100) }}%"></div>
    </div>
    <p class="kpi-note">{{ $note }}</p>
</article>
