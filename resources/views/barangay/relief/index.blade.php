@extends('layouts.staff')

@section('title', 'Relief Distribution')
@section('page-title', 'Relief Distribution')
@section('page-subtitle', 'Monitor stock, request supplies, and log relief packs given to evacuees.')

@php
    // Status -> badge. 'fulfilled' is in the special_relief_requests enum but no
    // code path can currently set it: reviewSpecial() writes only approved or
    // rejected. The intended lifecycle was pending -> approved -> fulfilled
    // (item physically handed over) and that last step was never built. Mapped
    // here so it displays correctly if Phase 3 wires it up.
    $reqBadge = [
        'pending' => 'badge-warning',
        'approved' => 'badge-success',
        'rejected' => 'badge-danger',
        'fulfilled' => 'badge-info',
    ];
@endphp

@section('page-actions')
    <button type="button" class="btn-secondary w-full sm:w-auto" data-open-modal="receiveModal">&#11015; Receive Stock</button>
    {{-- New. barangay.relief.request-restock and request-special have existed as
         routes with complete controller methods and a working City Admin
         approval queue since the relief module was built, but NO view referenced
         either one -- they were unreachable. Meanwhile the Distribute modal's
         textarea invited staff to type "diapers, maintenance meds, wheelchair",
         which saved a remark onto a ReliefTransaction and reached nobody. That is
         the reported "requests never appear on the City Admin side": not a
         backend failure, a missing button. --}}
    <button type="button" class="btn-secondary w-full sm:w-auto" data-open-modal="restockModal">&#8593; Request Stock</button>
    {{-- DROP C. Secondary, and BEFORE Distribute Relief on purpose. The batch
         path is the optional one -- single distribution is what this screen is
         for and stays the primary action, which also keeps it last so it remains
         the Enter-key default. --}}
    <button type="button" class="btn-secondary w-full sm:w-auto" data-open-modal="batchModal">&#128101; Batch Distribute</button>
    <button type="button" class="btn-primary w-full sm:w-auto" data-open-modal="distributeModal">&#128230; Distribute Relief</button>
@endsection

@section('content')
@unless($center)
    <div class="alert alert-warning">No evacuation center is registered for your barangay yet.</div>
@else
{{-- DROP B1. Four cards became five, so the wide breakpoint goes to five
     columns; at xl:grid-cols-4 the new card wrapped onto a row of its own.
     sm:grid-cols-2 is unchanged, which leaves it paired at tablet width and
     full-width on a phone. --}}
<section class="mb-4 grid grid-cols-1 items-stretch gap-3 sm:grid-cols-2 xl:grid-cols-5" aria-label="Relief stock summary">
    <article class="card kpi-card">
        <div class="kpi-head"><span class="kpi-title">Received</span></div>
        <p class="kpi-value" data-numeric>{{ number_format($stats['received']) }}</p>
        <p class="kpi-note">Total units received</p>
    </article>
    <article class="card kpi-card">
        <div class="kpi-head"><span class="kpi-title">Distributed</span></div>
        <p class="kpi-value" data-numeric>{{ number_format($stats['distributed']) }}</p>
        <p class="kpi-note">Total units given out</p>
    </article>
    <article class="card kpi-card">
        <div class="kpi-head"><span class="kpi-title">Remaining</span></div>
        <p class="kpi-value" data-numeric>{{ number_format($stats['remaining']) }}</p>
        <p class="kpi-note">Units on hand</p>
    </article>
    <article class="card kpi-card">
        <div class="kpi-head"><span class="kpi-title">Projected Stock</span></div>
        <p class="kpi-value" data-numeric>{{ $stats['days_left'] !== null ? '~' . $stats['days_left'] . ' days' : '-' }}</p>
        <p class="kpi-note">Estimate from 7-day avg. distribution rate</p>
    </article>
    {{-- DROP B1. The client tracks relief by peso value as well as by count.
         Sums stock-in rows only, so goods allocated between city and shelter
         without a donation behind them add nothing here. --}}
    <article class="card kpi-card">
        <div class="kpi-head"><span class="kpi-title">Value Received</span></div>
        <p class="kpi-value" data-numeric>PHP {{ number_format($stats['value_received'], 2) }}</p>
        <p class="kpi-note">Declared value of all stock received here</p>
    </article>
</section>

{{-- The disclosure that stops the four unit cards above reading as a lie.
     Financial Assistance stores pesos in its quantity by the client's
     decision, so it is excluded from every unit figure -- otherwise one
     5,000-peso grant would make "Received" read 5,200 where 200 packs
     arrived, and Projected Stock would be dividing pesos by packs. --}}
<p class="kpi-note mb-4">Unit figures count physical goods only. Cash items such as Financial Assistance are excluded from them and appear under Value Received and in the inventory table below.</p>

<section class="grid grid-cols-1 items-start gap-4 lg:grid-cols-3">
    <div class="lg:col-span-2">
        <form method="GET" class="filter-bar sm:grid sm:grid-cols-[1fr_auto] sm:items-end" role="search">
            <input type="search" name="q" value="{{ request('q') }}" placeholder="Search any member name&hellip;" aria-label="Search relief history by any member name">
            <button type="submit" class="btn-secondary">Apply</button>
        </form>

        <div class="card panel table-panel">
            <h2 class="panel-title">Distribution Log</h2>
            <table class="data-table" data-stack>
                <thead>
                    <tr>
                        <th scope="col">Date &amp; Time</th>
                        <th scope="col">Household Head</th>
                        <th scope="col">Family Size</th>
                        <th scope="col">Relief Given</th>
                        <th scope="col">Distributed By</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($log as $t)
                        <tr>
                            {{-- This cell was format('M d, Y &middot; h:i A'), which
                                 put an HTML entity inside a PHP DATE FORMAT string.
                                 m, i, d, o and t are all format characters, so
                                 every row of this log has been rendering as
                                 "Mar 15, 2026 &03421515202631; 05:42 PM". The ASCII
                                 entity convention is right; it just cannot be
                                 applied inside a format string. The separator is
                                 now outside the call. --}}
                            {{-- DROP C. A batch writes one of these rows per
                                 family per item, exactly as a single
                                 distribution does, so the only thing marking
                                 them as one act is batch_id. The badge sits
                                 INSIDE this cell rather than in a sixth column:
                                 the log already runs five columns under
                                 text-fit's 15px floor, and a new column would
                                 have meant new data-label handling on the
                                 stacked phone view for a one-word flag.

                                 Rows written before this drop have a null
                                 batch_id and render exactly as they always
                                 have. Nothing backfills them, and null is not
                                 missing data here -- it means single. --}}
                            <td data-label="Date &amp; Time" data-numeric>{{ $t->created_at->format('M d, Y') }} &middot; {{ $t->created_at->format('h:i A') }}
                                @if($t->batch_id)<br><span class="badge badge-info">Batch Distribution</span>@endif
                            </td>
                            <td data-label="Household Head">{{ $t->household?->headMember?->full_name ?? '-' }}</td>
                            <td data-label="Family Size" data-numeric>{{ $t->household?->number_of_members ?? '-' }}</td>
                            <td data-label="Relief Given">{{ $t->quantity }} {{ $t->reliefGood->unit }} - {{ $t->reliefGood->name }}
                                @if($t->remarks)<br><small class="text-ink-muted">Note: {{ $t->remarks }}</small>@endif
                            </td>
                            <td data-label="Distributed By">{{ $t->recordedBy?->name ?? '-' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="empty-note">No distributions logged yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
            {{ $log instanceof \Illuminate\Pagination\AbstractPaginator ? $log->links() : '' }}
        </div>

        <div class="card panel table-panel mt-4">
            <h2 class="panel-title">Current Inventory</h2>
            <table class="data-table" data-stack>
                <thead>
                    <tr>
                        <th scope="col">Item</th>
                        <th scope="col">On Hand</th>
                        <th scope="col">Reorder Level</th>
                        <th scope="col">Status</th>
                        <th scope="col">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($inventory as $inv)
                        @php $isLow = $inv->reorder_level > 0 && $inv->quantity_on_hand <= $inv->reorder_level; @endphp
                        <tr>
                            <td data-label="Item" data-fit>{{ $inv->reliefGood->name }} <small class="text-ink-muted">({{ $inv->reliefGood->unit }})</small></td>
                            <td data-label="On Hand" data-numeric>{{ number_format($inv->quantity_on_hand) }}</td>
                            <td data-label="Reorder Level" data-numeric>{{ number_format($inv->reorder_level) }}</td>
                            <td data-label="Status">
                                @if($isLow)
                                    <span class="badge badge-danger">Low stock</span>
                                @else
                                    <span class="badge badge-success">Stocked</span>
                                @endif
                            </td>
                            {{-- Requesting is offered at the moment the shortage is
                                 visible, prefilled with the item, rather than only
                                 from a toolbar button that needs the item picked
                                 again from a dropdown. --}}
                            <td class="actions-cell" data-label="Actions">
                                <button type="button" class="btn-link"
                                        data-open-modal="restockModal"
                                        data-request-good="{{ $inv->relief_good_id }}">
                                    {{ $isLow ? 'Request more' : 'Request' }}
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="empty-note">No stock recorded yet. Use "Receive Stock" when goods arrive.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- ======== DROP B1: Stock Receipts ========

             Before this drop NOTHING anywhere in the application rendered a
             `received` transaction -- both relief screens showed only the
             Distribution Log. Donor, value and remarks would have been written
             to the database and been invisible to the client who asked for
             them. Gotcha 37: a new field with nowhere to read it is write-only.

             ALWAYS RENDERED, with the true count in the heading and a written
             empty state. A panel that appears only when it has rows makes "no
             stock has arrived" and "this feature does not exist" look
             identical, and that cost a full debugging round in Phase 7.

             Deliberately not paginated -- the Distribution Log above already
             owns the `page` parameter, and a second paginator on the same
             screen would turn both tables at once. --}}
        <div class="card panel table-panel mt-4">
            <h2 class="panel-title">Stock Receipts <span class="badge badge-info">{{ number_format($receiptCount) }} total</span></h2>
            <p class="kpi-note">Everything that has arrived at this shelter, with who donated it. Showing the {{ $receipts->count() }} most recent.</p>
            <table class="data-table" data-stack>
                <thead>
                    <tr>
                        <th scope="col">Date</th>
                        <th scope="col">Item</th>
                        <th scope="col">Quantity</th>
                        <th scope="col">Donor</th>
                        <th scope="col">Value</th>
                        <th scope="col">Recorded By</th>
                        <th scope="col">Remarks</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($receipts as $r)
                        <tr>
                            <td data-label="Date" data-numeric>{{ $r->transaction_date?->format('M d, Y') }}</td>
                            <td data-label="Item" data-fit>{{ $r->reliefGood?->name ?? 'Item removed' }}</td>
                            <td data-label="Quantity" data-numeric>{{ number_format($r->quantity) }} {{ $r->reliefGood?->unit }}</td>
                            <td data-label="Donor">
                                {{ $r->donorTypeLabel() }}
                                @if($r->donor_name)<br><small class="text-ink-muted">{{ $r->donor_name }}</small>@endif
                            </td>
                            {{-- @if/@else rather than `?? '&mdash;'`: an HTML entity
                                 inside an escaped echo is double-encoded by e() and
                                 renders the literal text &mdash;. Gotcha 4. --}}
                            <td data-label="Value" data-numeric>@if($r->monetary_value !== null){{ 'PHP ' . number_format($r->monetary_value, 2) }}@else&mdash;@endif</td>
                            <td data-label="Recorded By">{{ $r->recordedBy?->name ?? '-' }}</td>
                            <td data-label="Remarks" data-fit>@if($r->remarks){{ $r->remarks }}@else&mdash;@endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="empty-note">No stock has been received at this shelter yet. Use "Receive Stock" when a delivery or donation arrives.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="flex flex-col gap-6">
        {{-- ---- Request status ----
             The whole point of this panel. A request that vanishes on submit is
             worse than no request button at all, because staff stop chasing it.
             Recently resolved requests stay listed with the reviewer's remarks so
             an approval or a rejection is visible here rather than only in the
             City Admin queue. --}}
        <div>
            <h2 class="panel-title">Stock &amp; Item Requests</h2>
            <div class="card panel activity-panel">
                @forelse($restockRequests as $r)
                    <div class="flex flex-col gap-1 border-b border-border p-3 text-sm last:border-b-0">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="font-medium">{{ $r->reliefGood?->name ?? 'Item removed' }}</span>
                            <span class="badge {{ $reqBadge[$r->status] ?? 'badge-info' }}">{{ ucfirst($r->status) }}</span>
                        </div>
                        <span class="text-ink-muted" data-numeric>
                            {{ number_format($r->requested_quantity) }} {{ $r->reliefGood?->unit }}
                            @if($r->requested_at) &middot; {{ $r->requested_at->diffForHumans() }} @endif
                        </span>
                        @if($r->status === 'approved')
                            <span class="text-success">&check; Approved &mdash; stock was added to this shelter's inventory automatically.</span>
                        @elseif($r->status === 'rejected')
                            <span class="text-danger">Not approved. Contact the CSWD Office if this is still needed.</span>
                        @endif
                    </div>
                @empty
                    <p class="empty-note">No stock requests yet. Use "Request Stock" when an item is running low.</p>
                @endforelse

                @foreach($specialRequests as $s)
                    <div class="flex flex-col gap-1 border-b border-border p-3 text-sm last:border-b-0">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="font-medium">{{ $s->item_description }}</span>
                            <span class="badge {{ $reqBadge[$s->status] ?? 'badge-info' }}">{{ ucfirst($s->status) }}</span>
                        </div>
                        <span class="text-ink-muted" data-numeric>
                            {{ number_format($s->quantity) }} for {{ $s->household?->headMember?->full_name ?? $s->household?->household_code ?? 'household removed' }}
                            &middot; {{ $s->created_at->diffForHumans() }}
                        </span>
                        @if($s->remarks)
                            <span class="text-ink-soft">Note: {{ $s->remarks }}</span>
                        @endif
                        @if($s->status === 'approved')
                            <span class="text-success">&check; Approved by the CSWD Office. The item is sourced outside the system, so it will not appear in inventory.</span>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>

        <div>
            <h2 class="panel-title">Priority: Not Yet Received</h2>
            <form method="GET" class="filter-bar sm:grid sm:grid-cols-[auto_1fr_auto] sm:items-end">
                <label for="since" class="tags-label">Since</label>
                <input type="date" id="since" name="since" value="{{ request('since', now()->subDays(3)->toDateString()) }}">
                <button type="submit" class="btn-secondary">Apply</button>
            </form>
            <div class="card panel activity-panel">
                @forelse($priority as $h)
                    <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1 border-b border-border p-3 text-sm last:border-b-0">
                        <span class="min-w-0 flex-1">{{ $h->headMember?->full_name ?? $h->household_code }}
                            <small class="text-ink-muted">&middot; {{ $h->number_of_members }} members</small></span>
                        <span class="text-ink-muted">
                            {{ $h->last_received ? 'Last: ' . $h->last_received->format('M d') : 'Never received' }}
                        </span>
                    </div>
                @empty
                    <p class="empty-note">Every checked-in household has received relief since the selected date. &check;</p>
                @endforelse
            </div>
        </div>
    </div>
</section>
@endunless
@endsection

@push('modals')
{{-- ======== Distribute Relief modal ======== --}}
{{-- DROP B2. Reopens itself on a REJECTED submit, carrying the operator's work
     back with it. Before this, a shortage produced abort(422) -- a bare error
     page -- and the household they had searched for, every item row and their
     remarks were gone.

     `hidden` is emitted only when this form was not the one that failed. The
     household id and the item rows come back from old(); the household header
     and the composition panel are refetched by staff.js, which watches for a
     form that is already visible with an id in it at load. --}}
@php
    $distFailed = $errors->any() && old('_form') === 'distribute';
    // Row 0 must always exist, so a failed submit with no items still renders
    // one empty row rather than an item list with no rows and no Add button
    // reachable.
    /* DROP C added a second form on this page that also posts `items`, so
       old('items') alone is no longer enough to identify whose rows these are:
       a rejected BATCH submit would otherwise repopulate this modal with the
       batch's item rows. Both forms are hidden in that case so nothing is
       visibly wrong, but an operator opening Distribute Relief afterwards would
       find someone else's quantities already in it. Gated on the same _form
       marker the failed flag above already uses. */
    $distOldItems = (old('_form') === 'distribute' && old('items')) ? old('items') : [['relief_good_id' => '', 'quantity' => 1]];
@endphp
<div class="modal-backdrop" id="distributeModal" @unless($distFailed) hidden @endunless>
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="distTitle">
        <div class="modal-head">
            <h2 id="distTitle">Distribute Relief</h2>
            <button type="button" class="icon-btn" data-close-modal aria-label="Close">&times;</button>
        </div>

        <div class="field search-inline">
            <label for="dist-search">Search by any member name</label>
            <div class="search-inline-row">
                <input type="search" id="dist-search" placeholder="Search household&hellip;" autocomplete="off">
            </div>
            <ul class="search-results" id="dist-results" hidden></ul>
        </div>

        <form method="POST" action="{{ route('barangay.relief.distribute') }}" id="distributeForm" @unless($distFailed) hidden @endunless>
            @csrf
            <input type="hidden" name="_form" value="distribute">
            <input type="hidden" name="household_id" id="dist-household-id" value="{{ old('household_id') }}">

            {{-- The stock shortage and the not-checked-in refusal both land
                 here, on the form, instead of on an error page. --}}
            @if($distFailed)
                <div class="alert alert-danger">
                    <ul>
                        @foreach($errors->all() as $message)
                            <li>{{ $message }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
            <div class="ci-profile">
                <p><strong id="dist-code"></strong> &middot; <span id="dist-head"></span></p>
                <p class="kpi-note" id="dist-tags-note"></p>
            </div>

            {{-- DROP B2: family composition + fixed-rule notes.

                 Filled by relief-composition.js from the household payload the
                 modal has already fetched -- no extra request, and NO AI or
                 model of any kind. The rules are a fixed table in that file, so
                 the same family always produces the same notes and any line on
                 screen can be traced to a line of code.

                 Starts hidden because there is no household selected yet; it is
                 revealed on selection, not on emptiness. --}}
            <div class="card panel mb-3" id="dist-composition" hidden>
                <h3 class="panel-title">Who you are issuing for</h3>
                <ul class="kpi-note" data-composition-facts></ul>
                <div class="kpi-note bg-info-bg text-info p-2" data-composition-notes></div>
                <small class="field-hint">Notes are reminders generated from this family's own records. They are not instructions and do not reserve or deduct stock.</small>
            </div>

            <div id="dist-items">
                {{-- Row 0 carries a Remove control like every other row. It used to
                     have none, and the Add-another-item handler cloned row 0's
                     innerHTML, so NO row could ever be removed -- a mis-tap meant
                     reopening the modal and starting again. The button is disabled
                     while a single row remains, rather than hidden, so it does not
                     appear and disappear as rows are added. --}}
                {{-- DROP B2. Rendered from old() so a rejected submit brings
                     every item row back. Normally this is exactly one empty
                     row, which is what it was before. The Remove control is
                     disabled while a single row remains, rather than hidden, so
                     it does not appear and disappear as rows are added. --}}
                @foreach($distOldItems as $i => $oldItem)
                    <div class="dist-item-row" data-item-row>
                        <select name="items[{{ $i }}][relief_good_id]" required aria-label="Relief good">
                            <option value="">Select item&hellip;</option>
                            @foreach($goods as $g)
                                <option value="{{ $g->id }}" @selected(($oldItem['relief_good_id'] ?? '') == $g->id)>{{ $g->name }} ({{ $g->unit }})</option>
                            @endforeach
                        </select>
                        <input type="number" name="items[{{ $i }}][quantity]" min="1" max="10000000" value="{{ $oldItem['quantity'] ?? 1 }}" required aria-label="Quantity">
                        <button type="button" class="btn-link btn-link-danger" data-remove-item aria-label="Remove this item" @disabled(count($distOldItems) <= 1)>&times; Remove</button>
                    </div>
                @endforeach
            </div>
            <button type="button" class="btn-link" id="addItemBtn">+ Add another item</button>

            <div class="field">
                {{-- Relabelled. This said "Special requests / notes (e.g. diapers,
                     maintenance meds, wheelchair - based on the family's tags)",
                     which described the special-item request feature exactly --
                     while actually writing a remark onto the transaction that
                     nobody reads. Staff followed the label and their requests
                     went nowhere. Notes are notes; requests have a button. --}}
                <label for="dist-remarks">Notes on this distribution <small>(optional &mdash; e.g. collected by a neighbour)</small></label>
                <textarea id="dist-remarks" name="remarks" rows="2" maxlength="500">{{ old('remarks') }}</textarea>
                <small class="field-hint">
                    Need an item that is not in stock, like diapers or maintenance medicine?
                    <button type="button" class="btn-link" id="dist-special-link">Request a special item for this family</button>
                    &mdash; a note here is not a request and does not reach the CSWD Office.
                </small>
            </div>

            <div class="modal-actions flex flex-col gap-2 sm:flex-row sm:justify-end">
                <button type="button" class="btn-secondary" data-close-modal>Cancel</button>
                <button type="submit" class="btn-primary">Log Distribution</button>
            </div>
        </form>
    </div>
</div>

{{-- Template for extra distribution rows. Replaces cloning row 0's innerHTML,
     which silently dropped anything the JS had attached and made a per-row
     Remove button impossible. __INDEX__ is substituted in staff.js. --}}
<template id="distItemTemplate">
    <div class="dist-item-row" data-item-row>
        <select name="items[__INDEX__][relief_good_id]" required aria-label="Relief good">
            <option value="">Select item&hellip;</option>
            @foreach($goods as $g)
                <option value="{{ $g->id }}">{{ $g->name }} ({{ $g->unit }})</option>
            @endforeach
        </select>
        <input type="number" name="items[__INDEX__][quantity]" min="1" value="1" required aria-label="Quantity">
        <button type="button" class="btn-link btn-link-danger" data-remove-item aria-label="Remove this item">&times; Remove</button>
    </div>
</template>

{{-- ======== DROP C: Batch Distribute modal ========

     ONE PACK, MANY FAMILIES. The item rows at the top describe what every
     ticked family receives; the checklist below chooses who. One submit writes
     one ReliefTransaction per family per item, all sharing one batch_id, which
     is what keeps each family credited in "Priority: Not Yet Received".

     ID PREFIX IS `bd-`, NOT `dist-`. Element ids are load-bearing on this page:
     it now carries five modals and two templates, and staff.js reaches into
     both by id. Nothing here may collide with the Distribute modal's dist-*
     ids or its [data-item-row] rows, hence the separate [data-batch-item-row]
     marker -- the two Add-another-item handlers are scoped to their own
     wrappers and must stay that way.

     REOPENS ITSELF ON A REJECTED SUBMIT, the same convention Drop B2
     established: its own `_form` value, its own failed flag, `hidden` emitted
     only when this was not the form that failed. A batch is the most work an
     operator can lose in one form on this screen -- ticking twenty-three
     families and then being handed a bare error page would be the worst version
     of the bug that convention exists to prevent. --}}
@php
    $batchFailed = $errors->any() && old('_form') === 'batch';

    /* Gated on _form for the same reason $distOldItems is: both forms post
       `items` and `remarks`, so old() alone cannot say which form they came
       from. array_values() re-indexes because old() preserves the submitted
       keys -- if the operator removed a middle row, old('items') comes back
       sparse as 0 and 2, and staff.js advances its index counter by counting
       rendered rows. Two rows counted, next index 2, and the new row would
       silently overwrite the existing items[2]. Contiguous keys make the count
       and the highest index the same number again. */
    $batchOldItems = array_values(
        ($batchFailed && old('items')) ? old('items') : [['relief_good_id' => '', 'quantity' => 1]]
    );

    /* Cast to int so the @checked comparison below can be strict. old() returns
       every value as a string, and `in_array($h->id, ['7'], true)` is false. */
    $batchOldHouseholds = $batchFailed ? array_map('intval', (array) old('households', [])) : [];
@endphp
<div class="modal-backdrop" id="batchModal" @unless($batchFailed) hidden @endunless>
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="batchTitle">
        <div class="modal-head">
            <h2 id="batchTitle">Batch Distribute Relief</h2>
            <button type="button" class="icon-btn" data-close-modal aria-label="Close">&times;</button>
        </div>

        {{-- Said before the form is filled in, not after it is refused. A
             control that behaves differently from the one beside it has to
             explain itself first. --}}
        <p class="kpi-note bg-info-bg text-info p-2">
            Every family you tick receives the <strong>same items</strong>, dated today.
            If one family needs something different, close this and use Distribute Relief for them.
        </p>

        <form method="POST" action="{{ route('barangay.relief.batch-distribute') }}" id="batchForm">
            @csrf
            <input type="hidden" name="_form" value="batch">

            @if($batchFailed)
                <div class="alert alert-danger">
                    <ul>
                        @foreach($errors->all() as $message)
                            <li>{{ $message }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <p class="panel-title">What each family receives</p>
            <div id="bd-items">
                @foreach($batchOldItems as $i => $oldItem)
                    <div class="dist-item-row" data-batch-item-row>
                        <select name="items[{{ $i }}][relief_good_id]" required aria-label="Relief good">
                            <option value="">Select item&hellip;</option>
                            @foreach($goods as $g)
                                <option value="{{ $g->id }}" @selected(($oldItem['relief_good_id'] ?? '') == $g->id)>{{ $g->name }} ({{ $g->unit }})</option>
                            @endforeach
                        </select>
                        <input type="number" name="items[{{ $i }}][quantity]" min="1" max="10000000" value="{{ $oldItem['quantity'] ?? 1 }}" required aria-label="Quantity per family">
                        {{-- Disabled rather than hidden while one row remains,
                             and the disabled state is in the SERVER-RENDERED
                             markup: delegation cannot observe a row being cloned
                             into the DOM, so the correct default has to ship
                             with the row. --}}
                        <button type="button" class="btn-link btn-link-danger" data-batch-remove-item aria-label="Remove this item" @disabled(count($batchOldItems) <= 1)>&times; Remove</button>
                    </div>
                @endforeach
            </div>
            <button type="button" class="btn-link" id="bd-add-item">+ Add another item</button>

            <div class="field">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <label id="bd-families-label">Families receiving this pack</label>
                    {{-- SELECT-ALL SCOPE. There is no filter box in this modal,
                         so "visible" and "eligible" are the same set and the
                         count in the label is the whole truth. If a search box
                         is ever added here, this label has to change with it --
                         a select-all that silently means "some of them" is worse
                         than no select-all. --}}
                    <label class="flex min-h-[44px] items-center gap-2">
                        <input type="checkbox" id="bd-select-all">
                        <span>Select all {{ $batchHouseholds->count() }} {{ $batchHouseholds->count() === 1 ? 'family' : 'families' }}</span>
                    </label>
                </div>
                <div class="checkbox-list max-h-64 overflow-y-auto" role="group" aria-labelledby="bd-families-label">
                    @forelse($batchHouseholds as $h)
                        <label class="flex min-h-[44px] flex-wrap items-center gap-2 px-2">
                            <input type="checkbox" name="households[]" value="{{ $h->id }}" data-batch-household @checked(in_array($h->id, $batchOldHouseholds, true))>
                            <span class="min-w-0 flex-1">{{ $h->headMember?->full_name ?? $h->household_code }}
                                <small class="text-ink-muted">&middot; {{ $h->household_code }} &middot; {{ $h->members_present }} present</small>
                            </span>
                            {{-- Badged, not filtered. A family who received rice
                                 yesterday is still standing in today's queue, so
                                 they stay on the list; this only says who has
                                 waited longest. --}}
                            @if($h->is_priority)<span class="badge badge-warning">Not yet received</span>@endif
                        </label>
                    @empty
                        <p class="empty-note">No family is checked in at this shelter yet, so there is nobody to distribute to.</p>
                    @endforelse
                </div>
            </div>

            {{-- TOTAL SUMMARY. Filled by staff.js from the controls above -- no
                 request, no stored state, just multiplication the operator would
                 otherwise do in their head while twenty-three families wait.
                 It is what turns "only 40 on hand" into something they can see
                 coming before they submit.

                 Rendered always, with a written empty state, rather than hidden
                 until it has something to say. A panel that appears and
                 disappears reads as a glitch. --}}
            <div class="card panel mb-3" id="bd-summary">
                <h3 class="panel-title">Total to be given out</h3>
                <ul class="kpi-note" data-batch-summary>
                    <li>Tick at least one family and choose an item to see the total.</li>
                </ul>
                <small class="field-hint">Stock is checked against this total when you submit. If any item is short, nothing is given out and every shortage is listed at once.</small>
            </div>

            <div class="field">
                <label for="bd-remarks">Notes on this batch <small>(optional &mdash; e.g. Typhoon relief pack, Day 3)</small></label>
                <textarea id="bd-remarks" name="remarks" rows="2" maxlength="500">{{ $batchFailed ? old('remarks') : '' }}</textarea>
                <small class="field-hint">The same note is saved against every family in this batch.</small>
            </div>

            <div class="modal-actions flex flex-col gap-2 sm:flex-row sm:justify-end">
                <button type="button" class="btn-secondary" data-close-modal>Cancel</button>
                {{-- btn-primary stays last so it remains the Enter-key default.
                     The confirmation dialog is built in staff.js rather than
                     data-confirm, because its text names the actual items,
                     quantities and family count -- a static string could not,
                     and this is the largest irreversible write this screen
                     performs. --}}
                <button type="submit" class="btn-primary" id="bd-submit">Log Batch Distribution</button>
            </div>
        </form>
    </div>
</div>

{{-- DROP C. Separate template from distItemTemplate: same row shape, different
     input marker and a different remove hook, so the two modals' handlers can
     never reach into each other's rows. __INDEX__ is substituted in staff.js. --}}
<template id="bdItemTemplate">
    <div class="dist-item-row" data-batch-item-row>
        <select name="items[__INDEX__][relief_good_id]" required aria-label="Relief good">
            <option value="">Select item&hellip;</option>
            @foreach($goods as $g)
                <option value="{{ $g->id }}">{{ $g->name }} ({{ $g->unit }})</option>
            @endforeach
        </select>
        <input type="number" name="items[__INDEX__][quantity]" min="1" max="10000000" value="1" required aria-label="Quantity per family">
        <button type="button" class="btn-link btn-link-danger" data-batch-remove-item aria-label="Remove this item">&times; Remove</button>
    </div>
</template>

{{-- ======== Receive Stock modal ========

     DROP B1. The single free-text "Source" field is gone. It asked one vague
     question and got one vague answer, which is precisely why the client could
     not report on where relief came from. Donor CATEGORY is a fixed list so it
     can be counted; donor NAME is free text and optional, because a private
     donor who does not want naming must not block the receipt.

     REOPENS ITSELF ON A VALIDATION ERROR. `hidden` is emitted only when this
     form was not the one that failed, so a rejected submit comes back with the
     modal open, the operator's input still in the fields and the message
     visible above. Done in Blade rather than JS: no ?open= deep link to strip,
     no new bundle, and nothing listens for evactech:modal-open on this modal so
     rendering it open is equivalent to opening it. --}}
@php
    // Built here rather than inline in the attribute: a php block is the
    // documented home for anything with logic in it, and this decides whether
    // an attribute is emitted at all.
    $recvFailed = $errors->any() && old('_form') === 'receive';
@endphp
<div class="modal-backdrop" id="receiveModal" @unless($recvFailed) hidden @endunless>
    <div class="modal modal-narrow" role="dialog" aria-modal="true" aria-labelledby="recvTitle">
        <div class="modal-head">
            <h2 id="recvTitle">Receive Stock</h2>
            <button type="button" class="icon-btn" data-close-modal aria-label="Close">&times;</button>
        </div>
        <p class="kpi-note">Goods that have physically arrived at this shelter. This adds them to inventory immediately.</p>
        <form method="POST" action="{{ route('barangay.relief.receive') }}">
            @csrf
            {{-- Marks which form failed, so only that modal reopens. --}}
            <input type="hidden" name="_form" value="receive">

            <div class="field">
                <label for="recv-good">Relief good</label>
                <select id="recv-good" name="relief_good_id">
                    <option value="">Select item&hellip;</option>
                    @foreach($goods as $g)
                        <option value="{{ $g->id }}" @selected(old('relief_good_id') == $g->id)>{{ $g->name }} ({{ $g->unit }})</option>
                    @endforeach
                </select>
                @error('relief_good_id')<small class="field-hint text-danger">{{ $message }}</small>@enderror
            </div>

            {{-- On-the-fly catalogue entry. Camp managers can do this by
                 decision: a donor at the door with something not on the list
                 must be recordable, or it gets filed under "Others" with the
                 truth buried in the remarks -- which is the problem the fixed
                 list was meant to solve.

                 A <details> disclosure rather than a JS toggle: no bundle
                 change, and it degrades to plain open markup with no script at
                 all. Forced open when this form failed so a typed name is not
                 hidden behind a closed panel with an error against it. --}}
            <details class="mb-3" @if($recvFailed && old('new_good_name')) open @endif>
                <summary class="btn-link cursor-pointer">Item not on the list? Add it here</summary>
                <div class="mt-2 grid grid-cols-1 gap-3 sm:grid-cols-[2fr_1fr]">
                    <div class="field">
                        <label for="recv-new-name">New item name</label>
                        <input type="text" id="recv-new-name" name="new_good_name" maxlength="100"
                               value="{{ old('new_good_name') }}" placeholder="e.g. Sopas">
                        @error('new_good_name')<small class="field-hint text-danger">{{ $message }}</small>@enderror
                    </div>
                    <div class="field">
                        <label for="recv-new-unit">Unit</label>
                        <input type="text" id="recv-new-unit" name="new_good_unit" maxlength="20"
                               value="{{ old('new_good_unit') }}" placeholder="e.g. pot">
                        @error('new_good_unit')<small class="field-hint text-danger">{{ $message }}</small>@enderror
                    </div>
                </div>
                <small class="field-hint">
                    The unit is part of what makes a stock line. Record different pack sizes as
                    different items &mdash; "Rice 50kg" in sacks and "Rice 5kg" in bags &mdash; so one
                    remaining sack is never mistaken for two bags. A name that already exists is reused.
                </small>
            </details>

            <div class="field">
                <label for="recv-qty">Quantity</label>
                <input type="number" id="recv-qty" name="quantity" min="1" max="10000000" value="{{ old('quantity') }}" required>
                <small class="field-hint">
                    For Financial Assistance the quantity is the peso amount &mdash; one unit per peso.
                    Cash is kept out of the unit counters at the top of this page.
                </small>
                @error('quantity')<small class="field-hint text-danger">{{ $message }}</small>@enderror
            </div>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div class="field">
                    <label for="recv-donor-type">Donor category</label>
                    <select id="recv-donor-type" name="donor_type" required>
                        <option value="">Select&hellip;</option>
                        @foreach($donorTypes as $key => $label)
                            <option value="{{ $key }}" @selected(old('donor_type') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('donor_type')<small class="field-hint text-danger">{{ $message }}</small>@enderror
                </div>
                <div class="field">
                    <label for="recv-donor-name">Donor name <small>(optional)</small></label>
                    <input type="text" id="recv-donor-name" name="donor_name" maxlength="255"
                           value="{{ old('donor_name') }}" placeholder="e.g. Cabuyao Rotary Club">
                    @error('donor_name')<small class="field-hint text-danger">{{ $message }}</small>@enderror
                </div>
            </div>

            <div class="field">
                <label for="recv-value">Declared value <small>(PHP)</small></label>
                <input type="number" id="recv-value" name="monetary_value" min="0" step="0.01"
                       value="{{ old('monetary_value') }}" required>
                <small class="field-hint">Enter 0 if the value is not known. Leave blank for Financial Assistance and the quantity is used.</small>
                @error('monetary_value')<small class="field-hint text-danger">{{ $message }}</small>@enderror
            </div>

            <div class="field">
                <label for="recv-remarks">Remarks <small>(optional)</small></label>
                <textarea id="recv-remarks" name="remarks" rows="2" maxlength="500">{{ old('remarks') }}</textarea>
                <small class="field-hint">Use this to say what an "Others" item actually was, or anything about the condition it arrived in.</small>
                @error('remarks')<small class="field-hint text-danger">{{ $message }}</small>@enderror
            </div>

            <div class="modal-actions flex flex-col gap-2 sm:flex-row sm:justify-end">
                <button type="button" class="btn-secondary" data-close-modal>Cancel</button>
                <button type="submit" class="btn-primary">Add to Inventory</button>
            </div>
        </form>
    </div>
</div>

{{-- ======== Request Stock modal (shelter-level restock) ======== --}}
<div class="modal-backdrop" id="restockModal" hidden>
    <div class="modal modal-narrow" role="dialog" aria-modal="true" aria-labelledby="restockTitle">
        <div class="modal-head">
            <h2 id="restockTitle">Request Stock from the City</h2>
            <button type="button" class="icon-btn" data-close-modal aria-label="Close">&times;</button>
        </div>
        {{-- Says plainly what approval does, because unlike the special-item
             request this one moves real stock on approval. --}}
        <p class="kpi-note">
            Asks the CSWD Office to send more of a standard relief item to this shelter.
            If it is approved the quantity is added to your inventory automatically &mdash;
            you do not need to record it again under Receive Stock.
        </p>
        <form method="POST" action="{{ route('barangay.relief.request-restock') }}">
            @csrf
            <div class="field">
                <label for="req-good">Relief good</label>
                <select id="req-good" name="relief_good_id" required>
                    <option value="">Select item&hellip;</option>
                    @foreach($goods as $g)
                        <option value="{{ $g->id }}">{{ $g->name }} ({{ $g->unit }})</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="req-qty">Quantity needed</label>
                <input type="number" id="req-qty" name="requested_quantity" min="1" required>
            </div>
            <div class="modal-actions flex flex-col gap-2 sm:flex-row sm:justify-end">
                <button type="button" class="btn-secondary" data-close-modal>Cancel</button>
                <button type="submit" class="btn-primary">Send Request</button>
            </div>
        </form>
    </div>
</div>

{{-- ======== Special Item Request modal (per household) ======== --}}
<div class="modal-backdrop" id="specialModal" hidden>
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="specialTitle">
        <div class="modal-head">
            <h2 id="specialTitle">Request a Special Item</h2>
            <button type="button" class="icon-btn" data-close-modal aria-label="Close">&times;</button>
        </div>
        {{-- Free text, deliberately. These items are by definition not in the
             relief_goods catalogue -- you would not pre-register a wheelchair
             with a reorder level. And unlike a restock, approval here records a
             decision; the item is sourced outside the system, so nothing lands
             in inventory. Saying so prevents staff waiting for stock that will
             never appear in the numbers. --}}
        <p class="kpi-note">
            For something a specific family needs that is not standard stock &mdash; newborn diapers,
            maintenance medicine, a wheelchair. The CSWD Office reviews each one.
            Approved items are sourced outside EvacTech, so they will not show up in your inventory.
        </p>

        <div class="field search-inline">
            <label for="sp-search">Which family is this for?</label>
            <div class="search-inline-row">
                <input type="search" id="sp-search" placeholder="Search any member name&hellip;" autocomplete="off">
            </div>
            <ul class="search-results" id="sp-results" hidden></ul>
        </div>

        <form method="POST" action="{{ route('barangay.relief.request-special') }}" id="specialForm" hidden>
            @csrf
            <input type="hidden" name="household_id" id="sp-household-id">
            <div class="ci-profile">
                <p><strong id="sp-code"></strong> &middot; <span id="sp-head"></span></p>
                <p class="kpi-note" id="sp-tags-note"></p>
            </div>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-[2fr_1fr]">
                <div class="field">
                    <label for="sp-item">What is needed?</label>
                    <input type="text" id="sp-item" name="item_description" required maxlength="255"
                           placeholder="e.g. Diapers (newborn)">
                </div>
                <div class="field">
                    <label for="sp-qty">How many?</label>
                    <input type="number" id="sp-qty" name="quantity" min="1" value="1" required>
                </div>
            </div>
            <div class="field">
                <label for="sp-remarks">Why is it needed? <small>(optional, but it helps the review)</small></label>
                <textarea id="sp-remarks" name="remarks" rows="2" maxlength="500"></textarea>
            </div>
            <div class="modal-actions flex flex-col gap-2 sm:flex-row sm:justify-end">
                <button type="button" class="btn-secondary" data-close-modal>Cancel</button>
                <button type="submit" class="btn-primary">Send Request</button>
            </div>
        </form>
    </div>
</div>
@endpush

@php
    // Built in a @php block, not inline: @json with => arrows or across lines
    // fails to parse.
    $goodsJson = json_encode($goods->map(function ($g) {
        return ['id' => $g->id, 'label' => $g->name . ' (' . $g->unit . ')'];
    })->values(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
@endphp

@push('scripts')
<script>
    window.ReliefConfig = {
        {{-- PHASE 8 ITEM 2. Was barangay.evacuees.search, which returns the
             whole roster plus unassigned households at any status. Both pickers
             fed by this config -- Distribute Relief and the special item request
             -- are prefilled now, so their default state is a list of families
             the operator can actually give something to: checked in, at this
             shelter. See ReliefController::searchRecipients().

             evacuees.search is unchanged and still backs the check-in picker on
             the shelter screen, which needs the opposite set: families who are
             NOT yet here. --}}
        searchUrl: "{{ route('barangay.relief.recipients') }}",
        showUrlTemplate: "{{ route('barangay.evacuees.show', ':id') }}",
        goods: {!! $goodsJson !!},
        autoOpen: {{ request('open') === 'distribute' ? 'true' : 'false' }},
    };
</script>
@endpush
