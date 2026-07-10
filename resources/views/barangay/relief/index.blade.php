@extends($layout ?? 'layouts.staff')

@section('title', 'Relief Distribution')
@section('page-title', 'Relief Distribution')
@section('page-subtitle', 'Monitor stock and log relief packs given to evacuees.')

@section('page-actions')
    <button type="button" class="btn-secondary" data-open-modal="receiveModal">"¬ Receive Stock</button>
    <button type="button" class="btn-primary" data-open-modal="distributeModal">ð¦ Distribute Relief</button>
@endsection

@section('content')
@isset($backLink)
    <a href="{{ $backLink }}" class="btn-link" style="display:inline-block;margin-bottom:var(--space-4);">&larr; Back to all shelters</a>
    @if($viewingCenter ?? null)<p class="page-subtitle" style="margin-bottom:var(--space-4);">Managing: <strong>{{ $viewingCenter->name }}</strong></p>@endif
@endisset
@unless($center)
    <div class="alert alert-warning">No evacuation center is registered for your barangay yet.</div>
@else
<section class="kpi-grid kpi-grid-4" aria-label="Relief stock summary">
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

<section class="dash-columns">
    <div>
        <form method="GET" class="filter-bar" role="search">
            <input type="search" name="q" value="{{ request('q') }}" placeholder="Search household head's relief history"¦" aria-label="Search household head">
            <button type="submit" class="btn-secondary">Search</button>
        </form>

        <div class="card panel table-panel">
            <h2 class="panel-title">Distribution Log</h2>
            <table class="data-table">
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
                            <td data-numeric>{{ $t->created_at->format('M d, Y Â· h:i A') }}</td>
                            <td>{{ $t->household?->headMember?->full_name ?? '-' }}</td>
                            <td data-numeric>{{ $t->household?->number_of_members ?? '-' }}</td>
                            <td>{{ $t->quantity }} {{ $t->reliefGood->unit }} - {{ $t->reliefGood->name }}
                                @if($t->remarks)<br><small class="text-muted">Note: {{ $t->remarks }}</small>@endif
                            </td>
                            <td>{{ $t->recordedBy?->name ?? '-' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="empty-note">No distributions logged yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
            {{ $log instanceof \Illuminate\Pagination\AbstractPaginator ? $log->links() : '' }}
        </div>

        <div class="card panel table-panel">
            <h2 class="panel-title">Current Inventory</h2>
            <table class="data-table">
                <thead><tr><th scope="col">Item</th><th scope="col">On Hand</th><th scope="col">Reorder Level</th><th scope="col">Status</th></tr></thead>
                <tbody>
                    @forelse($inventory as $inv)
                        <tr>
                            <td>{{ $inv->reliefGood->name }} <small class="text-muted">({{ $inv->reliefGood->unit }})</small></td>
                            <td data-numeric>{{ number_format($inv->quantity_on_hand) }}</td>
                            <td data-numeric>{{ number_format($inv->reorder_level) }}</td>
                            <td>
                                @if($inv->reorder_level > 0 && $inv->quantity_on_hand <= $inv->reorder_level)
                                    <span class="badge badge-danger">Low stock</span>
                                @else
                                    <span class="badge badge-success">Stocked</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="empty-note">No stock recorded yet. Use "Receive Stock" when goods arrive.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="dash-side">
        <h2 class="panel-title">Priority: Not Yet Received</h2>
        <form method="GET" class="filter-bar filter-bar-tight">
            <label for="since" class="tags-label">Since</label>
            <input type="date" id="since" name="since" value="{{ request('since', now()->subDays(3)->toDateString()) }}">
            <button type="submit" class="btn-secondary">Apply</button>
        </form>
        <div class="card panel activity-panel">
            @forelse($priority as $h)
                <div class="activity-row">
                    <span class="activity-name">{{ $h->headMember?->full_name ?? $h->household_code }}
                        <small class="text-muted">Â· {{ $h->number_of_members }} members</small></span>
                    <span class="activity-time">
                        {{ $h->last_received ? 'Last: ' . $h->last_received->format('M d') : 'Never received' }}
                    </span>
                </div>
            @empty
                <p class="empty-note">Every checked-in household has received relief since the selected date. ð</p>
            @endforelse
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
                <input type="search" id="dist-search" placeholder="Search household"¦" autocomplete="off">
            </div>
            <ul class="search-results" id="dist-results" hidden></ul>
        </div>

        <form method="POST" action="{{ route('barangay.relief.distribute') }}" id="distributeForm" hidden>
            @csrf
            <input type="hidden" name="household_id" id="dist-household-id">
            <div class="ci-profile">
                <p><strong id="dist-code"></strong> Â· <span id="dist-head"></span></p>
                <p class="kpi-note" id="dist-tags-note"></p>
            </div>

            <div id="dist-items">
                <div class="dist-item-row">
                    <select name="items[0][relief_good_id]" required aria-label="Relief good">
                        <option value="">Select item"¦</option>
                        @foreach($goods as $g)
                            <option value="{{ $g->id }}">{{ $g->name }} ({{ $g->unit }})</option>
                        @endforeach
                    </select>
                    <input type="number" name="items[0][quantity]" min="1" value="1" required aria-label="Quantity">
                </div>
            </div>
            <button type="button" class="btn-link" id="addItemBtn">+ Add another item</button>

            <div class="field">
                <label for="dist-remarks">Special requests / notes <small>(e.g. diapers, maintenance meds, wheelchair - based on the family's tags)</small></label>
                <textarea id="dist-remarks" name="remarks" rows="2" maxlength="500"></textarea>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn-secondary" data-close-modal>Cancel</button>
                <button type="submit" class="btn-primary">Log Distribution</button>
            </div>
        </form>
    </div>
</div>

{{-- ======== Receive Stock modal ======== --}}
<div class="modal-backdrop" id="receiveModal" hidden>
    <div class="modal modal-narrow" role="dialog" aria-modal="true" aria-labelledby="recvTitle">
        <div class="modal-head">
            <h2 id="recvTitle">Receive Stock</h2>
            <button type="button" class="icon-btn" data-close-modal aria-label="Close">&times;</button>
        </div>
        <form method="POST" action="{{ route('barangay.relief.receive') }}">
            @csrf
            <div class="field">
                <label for="recv-good">Relief good</label>
                <select id="recv-good" name="relief_good_id" required>
                    <option value="">Select item"¦</option>
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
            <div class="modal-actions">
                <button type="button" class="btn-secondary" data-close-modal>Cancel</button>
                <button type="submit" class="btn-primary">Add to Inventory</button>
            </div>
        </form>
    </div>
</div>
@endpush

@push('scripts')
<script>
    window.ReliefConfig = {
        searchUrl: "{{ route('barangay.evacuees.search') }}",
        showUrlTemplate: "{{ route('barangay.evacuees.show', ':id') }}",
        goods: @json($goods->map(fn ($g) => ['id' => $g->id, 'label' => $g->name . ' (' . $g->unit . ')'])),
        autoOpen: @json(request('open') === 'distribute'),
    };
</script>
@endpush
