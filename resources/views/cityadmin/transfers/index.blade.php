@extends('layouts.cityadmin')

@section('title', 'Shelter Transfers')
@section('page-title', 'Shelter Transfers')
@section('page-subtitle', 'Every family moving between shelters, city-wide')

@php
    // Same shape as the barangay page, different routes. The shared partials
    // read these templates and never call route() themselves.
    $tx = [
        'store' => route('city.transfers.store'),
        'search' => route('city.transfers.households'),
        'members' => route('city.transfers.members', ':id'),
        'confirm' => route('city.transfers.confirm', ':id'),
        'refuse' => route('city.transfers.refuse', ':id'),
        'depart' => route('city.transfers.depart', ':id'),
        'receive' => route('city.transfers.receive', ':id'),
        'cancel' => route('city.transfers.cancel', ':id'),
        // PHASE 5 ITEM 8b -- Resolve an absence.
        'resolve' => route('city.transfers.resolve', ':id'),
    ];
    $txConfig = $tx;
    $txConfig['centers'] = $centers;
@endphp

@section('page-actions')
    <button type="button" class="btn-primary w-full sm:w-auto" data-tx-create>&rarr; New Transfer</button>
@endsection

@section('content')
<section class="flex flex-col gap-4">
    <form method="GET" class="filter-bar sm:grid sm:grid-cols-2 sm:items-end lg:grid-cols-3" role="search">
        <input type="search" name="q" value="{{ request('q') }}" placeholder="Household code or any member name" aria-label="Search transfers">

        <select name="status" aria-label="Filter status">
            <option value="open" @selected(request('status', 'open') === 'open')>In progress</option>
            <option value="overdue" @selected(request('status') === 'overdue')>Overdue only</option>
            <option value="unaccounted" @selected(request('status') === 'unaccounted')>People not yet accounted for</option>
            <option value="pending" @selected(request('status') === 'pending')>Awaiting confirmation</option>
            <option value="approved" @selected(request('status') === 'approved')>Approved</option>
            <option value="in_transit" @selected(request('status') === 'in_transit')>In transit</option>
            <option value="completed" @selected(request('status') === 'completed')>Completed</option>
            <option value="refused" @selected(request('status') === 'refused')>Refused</option>
            <option value="cancelled" @selected(request('status') === 'cancelled')>Cancelled</option>
            <option value="all" @selected(request('status') === 'all')>All</option>
        </select>

        <button type="submit" class="btn-secondary">Apply</button>
    </form>

    <p class="text-sm text-ink-muted">
        Families stay counted at their origin shelter until arrival is recorded, so the city-wide total always
        reconciles. A transfer in transit for more than {{ $overdueMinutes }} minutes is flagged overdue: if the
        family never arrived, cancel it here and the household simply remains checked in where it started.
    </p>

    @include('partials.transfer-table', ['transfers' => $transfers, 'tx' => $tx])
</section>
@endsection

@push('modals')
    @include('partials.transfer-modals', ['tx' => $tx])
@endpush

@php
    
    $txConfigJson = json_encode($txConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
@endphp

@push('scripts')
<script>
    window.TransferConfig = {!! $txConfigJson !!};
</script>
@endpush
