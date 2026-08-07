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
    // PHASE 7 ITEM 7 (XSS sweep). Built in a @php block with the HEX flags
    // rather than json_encode() inline, matching the documented pattern used
    // elsewhere in this file.
    //
    // What this actually changes: json_encode() already escapes a forward
    // slash by default, so an operator-entered shelter name containing an
    // end-script sequence was emitted with the slash escaped and never closed
    // the block. The output was safe. It was safe BY DEFAULT, though, and one
    // JSON_UNESCAPED_SLASHES added later for readability would have removed
    // that protection silently. JSON_HEX_TAG escapes the angle brackets
    // themselves, which makes the safety explicit and independent of any other
    // flag.
    //
    // The array is built by the controller, so there are no => arrows here --
    // @json with arrows or across lines fails to parse.
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
