@extends('layouts.staff')

@section('title', 'Shelter Transfers')
@section('page-title', 'Shelter Transfers')
@section('page-subtitle', 'Families moving in and out of your shelters')

@php
    // Route templates are built ONCE here and handed to the shared partials, so
    // neither partial ever has to work out which role it is rendering for. That
    // question is what the Phase 1 rewrite removed from shared views.
    //
    // Built in a PHP block and printed with json_encode: a json directive containing
    // arrows or spanning lines does not survive Blade's parser (gotcha 1).
    $tx = [
        'store' => route('barangay.transfers.store'),
        'search' => route('barangay.transfers.households'),
        'members' => route('barangay.transfers.members', ':id'),
        'confirm' => route('barangay.transfers.confirm', ':id'),
        'refuse' => route('barangay.transfers.refuse', ':id'),
        'depart' => route('barangay.transfers.depart', ':id'),
        'receive' => route('barangay.transfers.receive', ':id'),
        'cancel' => route('barangay.transfers.cancel', ':id'),
        // PHASE 5 ITEM 8b -- Resolve an absence.
        'resolve' => route('barangay.transfers.resolve', ':id'),
    ];
    $txConfig = $tx;
    $txConfig['centers'] = $centers;
@endphp

@section('page-actions')
    <button type="button" class="btn-primary w-full sm:w-auto" data-tx-create>&rarr; New Transfer</button>
@endsection

@section('content')
<section class="flex flex-col gap-4">
    <form method="GET" class="filter-bar sm:grid sm:grid-cols-2 sm:items-end lg:grid-cols-4" role="search">
        <input type="search" name="q" value="{{ request('q') }}" placeholder="Household code or head name" aria-label="Search transfers">

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

        <select name="direction" aria-label="Filter direction">
            <option value="">Incoming and outgoing</option>
            <option value="incoming" @selected(request('direction') === 'incoming')>Incoming to my shelters</option>
            <option value="outgoing" @selected(request('direction') === 'outgoing')>Outgoing from my shelters</option>
        </select>

        <button type="submit" class="btn-secondary">Apply</button>
    </form>

    <p class="text-sm text-ink-muted">
        A family stays counted at their origin shelter until you record their arrival, so no headcount ever
        drops people mid-journey. A transfer still in transit after {{ $overdueMinutes }} minutes is flagged overdue.
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
    // Raw echo, not the escaped one. Blade's escaped echo runs the value through
    // e(), which turns every double quote in the JSON into an HTML entity. A
    // browser does not decode entities inside a script tag, so the assignment
    // threw a SyntaxError and window.TransferConfig was never set. JSON headed
    // for a script block always needs the raw echo.
    //
    // And note there is no Blade echo syntax anywhere in this comment: a JS
    // comment is still Blade source, so braces here would be compiled and would
    // break the whole file. Same trap as naming a Blade directive in a comment.
    window.TransferConfig = {!! $txConfigJson !!};
</script>
@endpush
