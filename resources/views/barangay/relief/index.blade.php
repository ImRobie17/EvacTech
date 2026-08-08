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
    <button type="button" class="btn-primary w-full sm:w-auto" data-open-modal="distributeModal">&#128230; Distribute Relief</button>
@endsection

@section('content')
@unless($center)
    <div class="alert alert-warning">No evacuation center is registered for your barangay yet.</div>
@else
<section class="mb-4 grid grid-cols-1 items-stretch gap-3 sm:grid-cols-2 xl:grid-cols-4" aria-label="Relief stock summary">
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
</section>

<section class="grid grid-cols-1 items-start gap-4 lg:grid-cols-3">
    <div class="lg:col-span-2">
        <form method="GET" class="filter-bar sm:grid sm:grid-cols-[1fr_auto] sm:items-end" role="search">
            <input type="search" name="q" value="{{ request('q') }}" placeholder="Search household head's relief history&hellip;" aria-label="Search household head">
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
                            <td data-label="Date &amp; Time" data-numeric>{{ $t->created_at->format('M d, Y') }} &middot; {{ $t->created_at->format('h:i A') }}</td>
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
                            <span class="text-danger">Not approved. Contact the CDRRMO if this is still needed.</span>
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
                            <span class="text-success">&check; Approved by the CDRRMO. The item is sourced outside the system, so it will not appear in inventory.</span>
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
<div class="modal-backdrop" id="distributeModal" hidden>
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="distTitle">
        <div class="modal-head">
            <h2 id="distTitle">Distribute Relief</h2>
            <button type="button" class="icon-btn" data-close-modal aria-label="Close">&times;</button>
        </div>

        <div class="field search-inline">
            <label for="dist-search">Household head name</label>
            <div class="search-inline-row">
                <input type="search" id="dist-search" placeholder="Search household&hellip;" autocomplete="off">
            </div>
            <ul class="search-results" id="dist-results" hidden></ul>
        </div>

        <form method="POST" action="{{ route('barangay.relief.distribute') }}" id="distributeForm" hidden>
            @csrf
            <input type="hidden" name="household_id" id="dist-household-id">
            <div class="ci-profile">
                <p><strong id="dist-code"></strong> &middot; <span id="dist-head"></span></p>
                <p class="kpi-note" id="dist-tags-note"></p>
            </div>

            <div id="dist-items">
                {{-- Row 0 carries a Remove control like every other row. It used to
                     have none, and the Add-another-item handler cloned row 0's
                     innerHTML, so NO row could ever be removed -- a mis-tap meant
                     reopening the modal and starting again. The button is disabled
                     while a single row remains, rather than hidden, so it does not
                     appear and disappear as rows are added. --}}
                <div class="dist-item-row" data-item-row>
                    <select name="items[0][relief_good_id]" required aria-label="Relief good">
                        <option value="">Select item&hellip;</option>
                        @foreach($goods as $g)
                            <option value="{{ $g->id }}">{{ $g->name }} ({{ $g->unit }})</option>
                        @endforeach
                    </select>
                    <input type="number" name="items[0][quantity]" min="1" value="1" required aria-label="Quantity">
                    <button type="button" class="btn-link btn-link-danger" data-remove-item aria-label="Remove this item" disabled>&times; Remove</button>
                </div>
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
                <textarea id="dist-remarks" name="remarks" rows="2" maxlength="500"></textarea>
                <small class="field-hint">
                    Need an item that is not in stock, like diapers or maintenance medicine?
                    <button type="button" class="btn-link" id="dist-special-link">Request a special item for this family</button>
                    &mdash; a note here is not a request and does not reach the CDRRMO.
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

{{-- ======== Receive Stock modal ======== --}}
<div class="modal-backdrop" id="receiveModal" hidden>
    <div class="modal modal-narrow" role="dialog" aria-modal="true" aria-labelledby="recvTitle">
        <div class="modal-head">
            <h2 id="recvTitle">Receive Stock</h2>
            <button type="button" class="icon-btn" data-close-modal aria-label="Close">&times;</button>
        </div>
        <p class="kpi-note">Goods that have physically arrived at this shelter. This adds them to inventory immediately.</p>
        <form method="POST" action="{{ route('barangay.relief.receive') }}">
            @csrf
            <div class="field">
                <label for="recv-good">Relief good</label>
                <select id="recv-good" name="relief_good_id" required>
                    <option value="">Select item&hellip;</option>
                    @foreach($goods as $g)
                        <option value="{{ $g->id }}">{{ $g->name }} ({{ $g->unit }})</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="recv-qty">Quantity</label>
                <input type="number" id="recv-qty" name="quantity" min="1" required>
            </div>
            <div class="field">
                <label for="recv-source">Source <small>(optional - e.g. CDRRMO delivery, donation)</small></label>
                <input type="text" id="recv-source" name="source" maxlength="255">
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
            Asks the CDRRMO to send more of a standard relief item to this shelter.
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
            maintenance medicine, a wheelchair. The CDRRMO reviews each one.
            Approved items are sourced outside EvacTech, so they will not show up in your inventory.
        </p>

        <div class="field search-inline">
            <label for="sp-search">Which family is this for?</label>
            <div class="search-inline-row">
                <input type="search" id="sp-search" placeholder="Search household head&hellip;" autocomplete="off">
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
