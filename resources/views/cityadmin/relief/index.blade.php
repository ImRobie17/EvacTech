@extends('layouts.cityadmin')

@section('title', 'Relief Distribution')
@section('page-title', 'Relief Distribution')
@section('page-subtitle', 'City-wide relief stock status and approval of special requests.')

@section('content')
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
                        <td data-label="Item" data-fit>{{ $r->reliefGood?->name }}</td>
                        <td data-label="Qty" data-numeric>{{ $r->requested_quantity }}</td>
                        <td data-label="Requested By">{{ $r->requestedBy?->name ?? '-' }}</td>
                        {{-- Approving a restock auto-increments inventory and
                             writes an allocated_in transaction: the loop closes
                             inside the system.

                             DROP B1. Approval now optionally records a DONOR.
                             A donation does not always reach a shelter
                             directly -- it often arrives at the CSWD Office
                             first and is allocated onward -- and capturing the
                             donor only on the direct receive path lost every
                             one of those from the value totals.

                             Left blank, this row is exactly what it always was:
                             city stock, no donor, no value, contributing
                             nothing to any total.

                             NOTE the cell does NOT use .actions-cell. That class
                             sets `display: inline-flex` on every direct child,
                             which collapses a <details> disclosure into one line
                             with its own summary. Plain flex utilities instead,
                             per the Tailwind-only styling rule. --}}
                        <td class="flex flex-col items-stretch gap-2" data-label="Decision">
                            <details>
                                <summary class="btn-link cursor-pointer">Approve&hellip;</summary>
                                <form method="POST" action="{{ route('city.relief.restock.review', $r) }}" class="mt-2 flex flex-col gap-2">
                                    @csrf
                                    <input type="hidden" name="decision" value="approve">
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
                                        If the camp manager will also record it on arrival, leave the value blank &mdash;
                                        otherwise the same donation is counted twice.
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
@endsection
