@extends('layouts.cityadmin')

@section('title', 'Relief Distribution')
@section('page-title', 'Relief Distribution')
@section('page-subtitle', 'City-wide relief stock status and approval of special requests.')

@section('content')
@if($errors->any())
    <div class="alert alert-danger" role="alert">
        <strong>Could not review the relief request.</strong>
        <ul class="list-disc pl-5">@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul>
    </div>
@endif
<div class="card panel table-panel">
    <h2 class="panel-title">Relief Stock by Shelter</h2>
    <table class="data-table" data-stack>
        <thead><tr><th scope="col">Shelter</th><th scope="col">Barangay</th><th scope="col">Total On Hand</th><th scope="col">Value Received</th><th scope="col">Low-stock Items</th><th scope="col">Status</th></tr></thead>
        <tbody>
            @forelse($shelters as $s)
                <tr>
                    <td data-label="Shelter" data-fit>{{ $s['name'] }}</td>
                    <td data-label="Barangay" data-fit>{{ $s['barangay'] }}</td>
                    <td data-label="On Hand" data-numeric>{{ number_format($s['on_hand']) }}</td>
                    {{-- DROP B1. Declared peso value of everything that arrived
                         here. Stock-in rows only. --}}
                    <td data-label="Value Received" data-numeric class="whitespace-nowrap">PHP {{ number_format($s['value_received'], 2) }}</td>
                    <td data-label="Low-stock" data-numeric>
                        @if($s['low_items'] > 0)<span class="badge badge-danger">{{ $s['low_items'] }} low</span>@else<span class="badge badge-success">OK</span>@endif
                    </td>
                    <td data-label="Status"><span class="badge {{ $s['status'] === 'active' ? 'badge-success' : 'badge-warning' }}">{{ ucfirst($s['status']) }}</span></td>
                </tr>
            @empty
                <tr><td colspan="6" class="empty-note">No shelters registered.</td></tr>
            @endforelse
        </tbody>
        {{-- City-wide donated value. Computed in the controller from the
             transactions rather than by adding the column above, so it stays
             correct if this list is ever filtered. --}}
        <tfoot>
            <tr>
                <td data-label="Total" colspan="3"><strong>City-wide value received</strong></td>
                <td data-label="City-wide total" data-numeric class="whitespace-nowrap"><strong>PHP {{ number_format($cityValueReceived, 2) }}</strong></td>
                <td colspan="2"></td>
            </tr>
        </tfoot>
    </table>
    <p class="kpi-note">Value counts stock that ARRIVED at a shelter, whether received there directly or allocated from an approved restock. Totals exclude nothing by shelter status.</p>
</div>

<section class="mt-4 grid grid-cols-1 items-start gap-4 lg:grid-cols-2">
    {{-- Restock requests --}}
    <div class="card panel table-panel">
        <h2 class="panel-title">Restock Requests <span class="badge badge-info">{{ $restockRequests->count() }} pending</span></h2>
        <table class="data-table" data-stack>
            <thead><tr><th scope="col">Shelter</th><th scope="col">Item</th><th scope="col">Qty</th><th scope="col">Requested By</th><th scope="col">Decision</th></tr></thead>
            <tbody>
                @forelse($restockRequests as $r)
                    <tr>
                        <td data-label="Shelter">{{ $r->evacuationCenter?->name }}</td>
                        <td data-label="Item" data-fit>{{ $r->custom_good_name ?? $r->reliefGood?->name ?? 'Unknown item' }}
                            @if($r->custom_description)<br><small class="text-ink-muted">{{ $r->custom_description }}</small>@endif
                            @if($r->request_group_id)<br><small class="text-ink-muted">Batch {{ substr($r->request_group_id, 0, 8) }}</small>@endif</td>
                        <td data-label="Qty" data-numeric>{{ $r->requested_quantity }}</td>
                        <td data-label="Requested By">{{ $r->requestedBy?->name ?? '-' }}</td>
                        {{-- Approval records a decision and optionally resolves a custom
                             catalogue item. Only the shelter can confirm delivery. --}}
                        <td class="flex flex-col items-stretch gap-2" data-label="Decision">
                            <details>
                                <summary class="btn-link cursor-pointer">Approve&hellip;</summary>
                                <form method="POST" action="{{ route('city.relief.restock.review', $r) }}" class="mt-2 flex flex-col gap-2">
                                    @csrf
                                    <input type="hidden" name="decision" value="approve">
                                    @if($r->relief_good_id === null)
                                        <div data-catalog-resolve class="rounded-md border border-border p-2">
                                            <p class="font-semibold text-sm">Resolve custom relief item</p>
                                            <label class="flex items-center gap-2 text-sm">
                                                <input type="radio" name="catalog_choice" value="existing" checked> Match existing catalogue item
                                            </label>
                                            <label class="flex items-center gap-2 text-sm">
                                                <input type="radio" name="catalog_choice" value="new"> Create new catalogue item
                                            </label>
                                            <div data-catalog-existing class="field">
                                                <label for="catalog-match-{{ $r->id }}">Existing relief good</label>
                                                <select id="catalog-match-{{ $r->id }}" name="matched_good_id">
                                                    <option value="">Choose existing item&hellip;</option>
                                                    @foreach($goods as $good)
                                                        <option value="{{ $good->id }}">{{ $good->name }} ({{ $good->unit }})</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div data-catalog-new class="field" hidden>
                                                <p class="text-sm">New item: <strong>{{ $r->custom_good_name }}</strong></p>
                                                <label for="catalog-unit-{{ $r->id }}">Unit of measurement</label>
                                                <input id="catalog-unit-{{ $r->id }}" type="text" name="new_good_unit" maxlength="20" placeholder="e.g. piece, pack, sack">
                                            </div>
                                        </div>
                                    @endif
                                    <p class="kpi-note">Approval does not add stock. The assigned Camp Manager must confirm arrival.</p>
                                    <div class="field">
                                        <label for="donor-type-{{ $r->id }}">Donor category <small>(optional)</small></label>
                                        <select id="donor-type-{{ $r->id }}" name="donor_type">
                                            <option value="">City stock &mdash; not a donation</option>
                                            @foreach($donorTypes as $key => $label)
                                                <option value="{{ $key }}">{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="field">
                                        <label for="donor-name-{{ $r->id }}">Donor name <small>(optional)</small></label>
                                        <input type="text" id="donor-name-{{ $r->id }}" name="donor_name" maxlength="255">
                                    </div>
                                    <div class="field">
                                        <label for="donor-value-{{ $r->id }}">Declared value <small>(PHP, optional)</small></label>
                                        <input type="number" id="donor-value-{{ $r->id }}" name="monetary_value" min="0" step="0.01">
                                    </div>
                                    <p class="kpi-note bg-info-bg text-info p-2">
                                        Only fill these in if this stock is a donation routed through the CSWD Office.
                                        Any declared value is recorded only when the Camp Manager confirms the delivery.
                                        The manager should not separately record the same goods under Receive Stock.
                                    </p>
                                    <button class="btn-primary">Confirm Approval</button>
                                </form>
                            </details>
                            <form method="POST" action="{{ route('city.relief.restock.review', $r) }}" class="inline-form">
                                @csrf <input type="hidden" name="decision" value="reject">
                                <button class="btn-link btn-link-danger">Reject</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="empty-note">No pending restock requests.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Special item requests --}}
    <div class="card panel table-panel">
        <h2 class="panel-title">Special Item Requests <span class="badge badge-info">{{ $specialRequests->count() }} pending</span></h2>
        {{-- Approving one of these only RECORDS a decision. There is no
             relief_good_id to increment and procurement happens outside the
             system, so nothing arrives in stock as a result. --}}
        <p class="kpi-note">Approving records a decision only &mdash; procurement happens outside EvacTech, so no stock is added automatically.</p>
        <table class="data-table" data-stack>
            <thead><tr><th scope="col">Household</th><th scope="col">Shelter</th><th scope="col">Item</th><th scope="col">Qty</th><th scope="col">Decision</th></tr></thead>
            <tbody>
                @forelse($specialRequests as $r)
                    <tr>
                        <td data-label="Household">{{ $r->household?->headMember?->full_name ?? $r->household?->household_code }}
                            @if($r->remarks)<br><small class="text-muted">{{ $r->remarks }}</small>@endif
                        </td>
                        <td data-label="Shelter">{{ $r->evacuationCenter?->name }}</td>
                        <td data-label="Item" data-fit>{{ $r->item_description }}</td>
                        <td data-label="Qty" data-numeric>{{ $r->quantity }}</td>
                        <td class="actions-cell" data-label="Decision">
                            <form method="POST" action="{{ route('city.relief.special.review', $r) }}" class="inline-form">
                                @csrf <input type="hidden" name="decision" value="approve">
                                <button class="btn-link">Approve</button>
                            </form>
                            <form method="POST" action="{{ route('city.relief.special.review', $r) }}" class="inline-form">
                                @csrf <input type="hidden" name="decision" value="reject">
                                <button class="btn-link btn-link-danger">Reject</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="empty-note">No pending special requests.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>

{{-- Approved allocations remain here until Camp Manager receipt or a recorded closure.
     This is not a stock ledger: approval alone never increments stock. --}}
<div class="card panel table-panel mt-4">
    <h2 class="panel-title">Outstanding Approved Deliveries
        <span class="badge badge-info">{{ $awaitingDeliveries->count() }} awaiting receipt</span>
    </h2>
    <p class="kpi-note">Stock increases only after the assigned Camp Manager confirms the amount physically received. If a shipment cannot be completed, close the remaining balance with a reason.</p>
    <table class="data-table" data-stack>
        <thead><tr><th scope="col">Shelter</th><th scope="col">Relief Good</th><th scope="col">Received / Approved</th><th scope="col">Last Arrival Note</th><th scope="col">Close Shortage</th></tr></thead>
        <tbody>
            @forelse($awaitingDeliveries as $delivery)
                <tr>
                    <td data-label="Shelter">{{ $delivery->evacuationCenter?->name ?? 'Shelter removed' }}</td>
                    <td data-label="Relief Good">{{ $delivery->reliefGood?->name ?? $delivery->custom_good_name }}
                        @if($delivery->request_group_id)<br><small class="text-ink-muted">Batch {{ substr($delivery->request_group_id, 0, 8) }}</small>@endif
                    </td>
                    <td data-label="Received / Approved" data-numeric>
                        {{ number_format($delivery->received_quantity) }} / {{ number_format($delivery->requested_quantity) }} {{ $delivery->reliefGood?->unit }}
                        <br><small class="text-ink-muted">{{ number_format($delivery->remaining_quantity) }} outstanding</small>
                    </td>
                    <td data-label="Last Arrival Note">{{ $delivery->latestReceipt?->remarks ?: 'No arrival confirmed yet' }}</td>
                    <td data-label="Close Shortage">
                        <details>
                            <summary class="btn-link cursor-pointer">Resolve undelivered amount</summary>
                            <form method="POST" action="{{ route('city.relief.restock.close', $delivery) }}" class="mt-2 flex flex-col gap-2">
                                @csrf
                                <label for="close-reason-{{ $delivery->id }}">Reason (required)</label>
                                <textarea id="close-reason-{{ $delivery->id }}" name="delivery_closure_reason" maxlength="500" rows="2" required placeholder="Delivery cancelled, supplier shortage, damaged items, etc."></textarea>
                                <button type="submit" class="btn-secondary">Close Remaining Delivery</button>
                            </form>
                        </details>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="empty-note">No approved deliveries currently awaiting arrival.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
