@extends('layouts.cityadmin')

@section('title', 'Relief Distribution')
@section('page-title', 'Relief Distribution')
@section('page-subtitle', 'City-wide relief stock status and approval of special requests.')

@section('content')
<div class="card panel table-panel">
    <h2 class="panel-title">Relief Stock by Shelter</h2>
    <table class="data-table" data-stack>
        <thead><tr><th scope="col">Shelter</th><th scope="col">Barangay</th><th scope="col">Total On Hand</th><th scope="col">Low-stock Items</th><th scope="col">Status</th></tr></thead>
        <tbody>
            @forelse($shelters as $s)
                <tr>
                    <td data-label="Shelter">{{ $s['name'] }}</td>
                    <td data-label="Barangay">{{ $s['barangay'] }}</td>
                    <td data-label="On Hand" data-numeric>{{ number_format($s['on_hand']) }}</td>
                    <td data-label="Low-stock" data-numeric>
                        @if($s['low_items'] > 0)<span class="badge badge-danger">{{ $s['low_items'] }} low</span>@else<span class="badge badge-success">OK</span>@endif
                    </td>
                    <td data-label="Status"><span class="badge {{ $s['status'] === 'active' ? 'badge-success' : 'badge-warning' }}">{{ ucfirst($s['status']) }}</span></td>
                </tr>
            @empty
                <tr><td colspan="5" class="empty-note">No shelters registered.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

{{-- The two approval queues sit side by side from 1024px and stack below.
     .dash-columns did this before; it gave both equal width at every size
     including 380px, where two five-column tables side by side were unusable. --}}
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
                        <td data-label="Item">{{ $r->reliefGood?->name }}</td>
                        <td data-label="Qty" data-numeric>{{ $r->requested_quantity }}</td>
                        <td data-label="Requested By">{{ $r->requestedBy?->name ?? '-' }}</td>
                        {{-- Approving a restock auto-increments inventory and
                             writes an allocated_in transaction: the loop closes
                             inside the system. --}}
                        <td class="actions-cell" data-label="Decision">
                            <form method="POST" action="{{ route('city.relief.restock.review', $r) }}" class="inline-form">
                                @csrf <input type="hidden" name="decision" value="approve">
                                <button class="btn-link">Approve</button>
                            </form>
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
                        <td data-label="Item">{{ $r->item_description }}</td>
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
