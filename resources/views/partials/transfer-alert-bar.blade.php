{{--
    PHASE 2 ITEM 8 -- persistent transfer alert bar.

    Pinned at the top of the content area on the Barangay and City Admin
    layouts. Not on Super Admin (who has system alerts instead) and never on the
    public site.

    Fed by the layouts.staff / layouts.cityadmin view composer in
    AppServiceProvider, so no existing controller had to be edited to make it
    appear on every screen.

    Everything here is computed ON READ. There is no scheduler in this project,
    and a notification system that depended on Windows Task Scheduler being
    configured would silently show nothing on the defence machine.

    Styling is Tailwind utilities plus the EXISTING .alert / .badge classes from
    staff.css, which already carry the light and dark palettes. No new CSS.
--}}
@php
    $tAlerts = $transferAlerts ?? null;
    $tUrl = $transferAlertsUrl ?? null;
    $tNeeds = (int) ($tAlerts['needs_action'] ?? 0);
    $tOverdue = (int) ($tAlerts['overdue'] ?? 0);

    // Build the sentence fragments in PHP: a json directive with arrows inside the
    // markup does not survive Blade's parser (gotcha 1).
    $tParts = [];
    if (($tAlerts['awaiting_confirmation'] ?? 0) > 0) {
        $tParts[] = $tAlerts['awaiting_confirmation'] . ' awaiting your confirmation';
    }
    if (($tAlerts['awaiting_departure'] ?? 0) > 0) {
        $tParts[] = $tAlerts['awaiting_departure'] . ' ready to depart';
    }
    if (($tAlerts['awaiting_receipt'] ?? 0) > 0) {
        $tParts[] = $tAlerts['awaiting_receipt'] . ' arriving, not yet received';
    }
    $tSentence = implode(', ', $tParts);

    // PHASE 5 ITEM 8b. Counted in PEOPLE, matching the capacity panel, so the
    // same idea does not carry two units one screen apart.
    $tUnaccounted = (int) ($tAlerts['unaccounted_people'] ?? 0);
@endphp

@if ($tAlerts && $tUrl && ($tNeeds > 0 || $tOverdue > 0 || $tUnaccounted > 0))
    <div class="{{ $tOverdue > 0 ? 'alert alert-danger' : 'alert alert-warning' }} flex flex-wrap items-center gap-x-3 gap-y-2"
         role="status">
        <span aria-hidden="true">&#9888;</span>

        <span class="min-w-0 flex-1">
            <strong>Shelter transfers</strong>
            @if ($tSentence)
                <span>{{ $tSentence }}.</span>
            @endif
            @if ($tOverdue > 0)
                {{-- Status is never colour-only: the overdue count is spelled
                     out in words as well as carrying the red treatment. --}}
                <strong>{{ $tOverdue }} overdue</strong>
                <span>(in transit longer than {{ $transferOverdueMinutes ?? 60 }} minutes).</span>
            @endif
            @if ($tUnaccounted > 0)
                {{-- NEVER "missing". That is a formal NDRRMC category that travels
                     upward beside dead and injured, and all the system knows is
                     that a headcount did not reconcile. --}}
                <strong>{{ $tUnaccounted }}</strong>
                <span>{{ $tUnaccounted === 1 ? 'person' : 'people' }} not yet accounted for after a transfer.</span>
            @endif
        </span>

        <a href="{{ $tUrl }}" class="btn-link inline-flex min-h-tap items-center">Open Shelter Transfers &rarr;</a>
    </div>
@endif
