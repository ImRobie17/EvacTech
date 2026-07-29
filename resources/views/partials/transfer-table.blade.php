{{--
    PHASE 2 ITEM 8 -- the transfers table, shared by both roles.

    SHARING THIS IS DELIBERATE AND SAFE, unlike the cityChrome() mechanism that
    Phase 1 removed. The bug there was that shared views asked "which role am I
    rendering for?" in order to pick a route, and every new action was a fresh
    chance to get it wrong. This partial contains ZERO role branching: it is
    handed a $tx array of fully-built URL templates by whichever page includes
    it, and it never calls route() itself.

    Required variables:
      $transfers   paginator of ShelterTransfer
      $tx          array of route templates (see either transfers/index view)
--}}
@php
    $txUser = auth()->user();
@endphp

<div class="card panel table-panel">
    {{-- data-stack plus a data-label on every cell: below 768px this becomes
         labelled cards. The two attributes always go together -- data-stack
         without the labels prints a blank gutter down every card. --}}
    <table class="data-table" data-stack>
        <thead>
            <tr>
                <th scope="col">Household</th>
                <th scope="col">From</th>
                <th scope="col">To</th>
                <th scope="col">Status</th>
                <th scope="col">OUT</th>
                <th scope="col">IN</th>
                <th scope="col">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($transfers as $t)
                <tr>
                    <td data-label="Household">
                        <strong>{{ $t->household?->household_code ?? '-' }}</strong>
                        <span class="block text-sm text-ink-muted">{{ $t->household?->headMember?->full_name ?? '-' }}</span>
                        <span class="block text-sm text-ink-muted">{{ $t->members_expected }} travelling</span>
                        @if ($t->origin_checked_in_at)
                            {{-- The check-in time at the ORIGIN, snapshotted when this
                                 transfer was raised. households.checked_in_at is reset
                                 to the arrival time on receipt, so this row is where the
                                 earlier stay is preserved for the record. --}}
                            <span class="block text-sm text-ink-muted">Checked in at origin {{ $t->origin_checked_in_at->format('M d, Y h:i A') }}</span>
                        @endif
                    </td>

                    <td data-label="From">
                        {{ $t->fromCenter?->name ?? '-' }}
                        @if ($t->fromCenter?->barangay)
                            <span class="block text-sm text-ink-muted">Brgy. {{ $t->fromCenter->barangay->name }}</span>
                        @endif
                    </td>

                    <td data-label="To">
                        {{ $t->toCenter?->name ?? '-' }}
                        @if ($t->toCenter?->barangay)
                            <span class="block text-sm text-ink-muted">Brgy. {{ $t->toCenter->barangay->name }}</span>
                        @endif
                    </td>

                    <td data-label="Status">
                        <span class="badge {{ $t->badgeClass() }}">{{ $t->statusLabel() }}</span>
                        @if ($t->isOverdue())
                            <span class="block text-sm text-ink-muted">Overdue: {{ $t->minutesInTransit() }} minutes in transit</span>
                        @endif
                        @if ($t->status === \App\Models\ShelterTransfer::REFUSED && $t->refusal_reason)
                            <span class="block text-sm text-ink-muted">Refused: {{ $t->refusal_reason }}</span>
                        @endif
                        @if ($t->status === \App\Models\ShelterTransfer::CANCELLED && $t->cancellation_reason)
                            <span class="block text-sm text-ink-muted">Cancelled: {{ $t->cancellation_reason }}</span>
                        @endif
                        @if ($t->status === \App\Models\ShelterTransfer::COMPLETED && $t->members_received !== null && $t->members_received < $t->members_expected)
                            <span class="block text-sm text-ink-muted">{{ $t->members_received }} of {{ $t->members_expected }} arrived</span>
                        @endif
                        @if ($t->reason)
                            <span class="block text-sm text-ink-muted">Reason: {{ $t->reason }}</span>
                        @endif
                    </td>

                    {{-- Separator characters stay OUTSIDE format(): a dash inside
                         the format string is a token waiting to happen. --}}
                    <td data-label="OUT" data-numeric>
                        {{ $t->departed_at?->format('M d, Y h:i A') ?? '-' }}
                        @if ($t->departedBy)
                            <span class="block text-sm text-ink-muted">by {{ $t->departedBy->name }}</span>
                        @endif
                    </td>

                    <td data-label="IN" data-numeric>
                        {{ $t->received_at?->format('M d, Y h:i A') ?? '-' }}
                        @if ($t->receivedBy)
                            <span class="block text-sm text-ink-muted">by {{ $t->receivedBy->name }}</span>
                        @endif
                    </td>

                    <td class="actions-cell" data-label="Actions">
                        @if ($t->canBeConfirmedBy($txUser))
                            <form method="POST" action="{{ str_replace(':id', $t->id, $tx['confirm']) }}" class="inline-form"
                                  data-confirm="Confirm you will accept this family at your shelter?">
                                @csrf
                                <button type="submit" class="btn-link">Confirm</button>
                            </form>
                        @endif

                        @if ($t->canBeRefusedBy($txUser))
                            <button type="button" class="btn-link btn-link-danger"
                                    data-tx-refuse="{{ $t->id }}"
                                    data-tx-label="{{ $t->household?->household_code }}">Refuse</button>
                        @endif

                        @if ($t->canBeDepartedBy($txUser))
                            <form method="POST" action="{{ str_replace(':id', $t->id, $tx['depart']) }}" class="inline-form"
                                  data-confirm="Record that this family has left the shelter now?">
                                @csrf
                                <button type="submit" class="btn-link">Record OUT</button>
                            </form>
                        @endif

                        @if ($t->canBeReceivedBy($txUser))
                            <button type="button" class="btn-link"
                                    data-tx-receive="{{ $t->id }}">Receive</button>
                        @endif

                        @if ($t->canBeCancelledBy($txUser))
                            <button type="button" class="btn-link btn-link-danger"
                                    data-tx-cancel="{{ $t->id }}"
                                    data-tx-label="{{ $t->household?->household_code }}"
                                    data-tx-in-transit="{{ $t->status === \App\Models\ShelterTransfer::IN_TRANSIT ? '1' : '0' }}">Cancel</button>
                        @endif

                        @if (! $t->isOpen())
                            <span class="text-sm text-ink-muted">Closed</span>
                        @endif
                    </td>
                </tr>
            @empty
                {{-- No data-label on a colspan cell: there is no column to name,
                     and staff.css exempts td.empty-note from the stacked labels. --}}
                <tr><td colspan="7" class="empty-note">No shelter transfers match this filter.</td></tr>
            @endforelse
        </tbody>
    </table>

    @if ($transfers instanceof \Illuminate\Pagination\AbstractPaginator)
        {{ $transfers->links() }}
    @endif
</div>
