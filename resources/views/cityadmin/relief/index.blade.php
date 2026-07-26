@extends('layouts.cityadmin')

@section('title', 'Relief Distribution')
@section('page-title', 'Relief Distribution')
@section('page-subtitle', 'City-wide relief stock status and approval of special requests.')

@section('content')
<div class="card panel table-panel">
    <h2 class="panel-title">Relief Stock by Shelter</h2>
    <table class="data-table">
        <thead><tr><th scope="col">Shelter</th><th scope="col">Barangay</th><th scope="col">Total On Hand</th><th scope="col">Low-stock Items</th><th scope="col">Status</th></tr></thead>
        <tbody>
            @forelse($shelters as $s)
                <tr>
                    <td>{{ $s['name'] }}</td>
                    <td>{{ $s['barangay'] }}</td>
                    <td data-numeric>{{ number_format($s['on_hand']) }}</td>
                    <td data-numeric>
                        @if($s['low_items'] > 0)<span class="badge badge-danger">{{ $s['low_items'] }} low</span>@else<span class="badge badge-success">OK</span>@endif
                    </td>
                    <td><span class="badge {{ $s['status'] === 'active' ? 'badge-success' : 'badge-warning' }}">{{ ucfirst($s['status']) }}</span></td>
                </tr>
            @empty
                <tr><td colspan="5" class="empty-note">No shelters registered.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<section class="dash-columns">
    {{-- Restock requests --}}
    <div class="card panel table-panel">
        <h2 class="panel-title">Restock Requests <span class="badge badge-info">{{ $restockRequests->count() }} pending</span></h2>
        <table class="data-table">
            <thead><tr><th scope="col">Shelter</th><th scope="col">Item</th><th scope="col">Qty</th><th scope="col">Requested By</th><th scope="col">Decision</th></tr></thead>
            <tbody>
                @forelse($restockRequests as $r)
                    <tr>
                        <td>{{ $r->evacuationCenter?->name }}</td>
                        <td>{{ $r->reliefGood?->name }}</td>
                        <td data-numeric>{{ $r->requested_quantity }}</td>
                        <td>{{ $r->requestedBy?->name ?? '—' }}</td>
                        <td class="actions-cell">
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
        <table class="data-table">
            <thead><tr><th scope="col">Household</th><th scope="col">Shelter</th><th scope="col">Item</th><th scope="col">Qty</th><th scope="col">Decision</th></tr></thead>
            <tbody>
                @forelse($specialRequests as $r)
                    <tr>
                        <td>{{ $r->household?->headMember?->full_name ?? $r->household?->household_code }}
                            @if($r->remarks)<br><small class="text-muted">{{ $r->remarks }}</small>@endif
                        </td>
                        <td>{{ $r->evacuationCenter?->name }}</td>
                        <td>{{ $r->item_description }}</td>
                        <td data-numeric>{{ $r->quantity }}</td>
                        <td class="actions-cell">
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
