{{--
    Occupancy figure for a table cell: "120 / 500 (24%)" plus an overcapacity
    note. Data in, markup out -- no routes, no role.
--}}
@php
    $pct = $center->capacity > 0 ? round($center->current_occupancy / $center->capacity * 100) : null;
@endphp
<span class="cap-{{ $center->capacityBand() }}">
    {{ number_format($center->current_occupancy) }} / {{ number_format($center->capacity) }}
    @if ($pct !== null)({{ $pct }}%)@endif
</span>
@if ($center->isOvercapacity())
    <small class="over-note">+{{ number_format($center->overBy()) }} over capacity</small>
@endif
