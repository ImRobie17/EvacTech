<?php

namespace App\Services;

use App\Models\EvacuationCenter;
use App\Models\Household;
use App\Models\ShelterTransfer;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * PHASE 2 ITEM 8 -- every shelter-transfer state change lives here.
 *
 * WHY A SERVICE: the Barangay and City Admin sides both drive the same six
 * transitions, and each transition has to touch the transfer row, two shelters'
 * occupancy and the audit log in the right order inside one database
 * transaction. syncMembers() was copy-pasted into four controllers and that
 * duplication is exactly why the vulnerable-tags bug survived a whole phase
 * (see HouseholdMemberSync). This module does not repeat that mistake: the
 * controllers validate input and redirect, and nothing else.
 *
 * OCCUPANCY CONTRACT: recalcOccupancy() is unchanged and still just sums
 * members_present over checked-in households. Only receive() moves a household
 * between shelters, and it is the only method here that recalculates anything.
 * Every other transition is metadata on the transfer row.
 */
class TransferService
{
    // -----------------------------------------------------------------
    // Transitions
    // -----------------------------------------------------------------

    /**
     * Raise a transfer. Callable by staff at EITHER end (the origin sending a
     * family on, or the destination pulling one in) and by City Admin.
     */
    public function request(Household $household, EvacuationCenter $destination, User $actor, ?string $reason = null): ShelterTransfer
    {
        return DB::transaction(function () use ($household, $destination, $actor, $reason) {
            // Lock the household for the duration: two staff members hitting
            // Transfer on the same family at the same moment must not both pass
            // the "no open transfer" check below.
            $household = Household::whereKey($household->id)->lockForUpdate()->firstOrFail();

            if ($household->status !== 'checked_in') {
                $this->fail('household', 'Only a household that is currently checked in can be transferred.');
            }

            $origin = $household->evacuationCenter;

            if (! $origin) {
                $this->fail('household', 'This household is not assigned to a shelter.');
            }

            if ($origin->id === $destination->id) {
                $this->fail('to_center_id', 'The destination must be a different shelter.');
            }

            if ($destination->status !== 'active') {
                $this->fail('to_center_id', 'The destination shelter is not active.');
            }

            if ($this->openTransferFor($household)) {
                $this->fail('household', 'This household already has a transfer in progress.');
            }

            // Authorisation follows the shelters, through the
            // evacuation_center_user pivot. Never a barangay id comparison.
            if (! $actor->canAccessCenter($origin->id) && ! $actor->canAccessCenter($destination->id)) {
                abort(403, 'You are not assigned to either shelter in this transfer.');
            }

            $transfer = ShelterTransfer::create([
                'household_id' => $household->id,
                'from_center_id' => $origin->id,
                'to_center_id' => $destination->id,
                'status' => ShelterTransfer::PENDING,
                'reason' => $reason,
                // Snapshot before anything moves. See the migration comment.
                'origin_checked_in_at' => $household->checked_in_at,
                'members_expected' => (int) $household->members_present,
                'requested_by' => $actor->id,
                'requested_at' => now(),
            ]);

            AuditLogger::log('created', $transfer, sprintf(
                'Requested transfer of %s from %s to %s (%d expected)',
                $household->household_code,
                $origin->name,
                $destination->name,
                (int) $household->members_present
            ));

            return $transfer;
        });
    }

    /** Destination accepts. The family has not moved yet. */
    public function confirm(ShelterTransfer $transfer, User $actor): ShelterTransfer
    {
        return DB::transaction(function () use ($transfer, $actor) {
            $transfer = $this->lock($transfer);
            $this->authorize($transfer->canBeConfirmedBy($actor), 'confirm');

            $transfer->update([
                'status' => ShelterTransfer::APPROVED,
                'confirmed_by' => $actor->id,
                'confirmed_at' => now(),
            ]);

            AuditLogger::log('updated', $transfer, sprintf(
                'Confirmed transfer of %s into %s',
                $transfer->household?->household_code,
                $transfer->toCenter?->name
            ));

            return $transfer;
        });
    }

    /**
     * Destination refuses. Only from pending -- once confirmed, the destination
     * is committed and must receive then re-transfer instead.
     */
    public function refuse(ShelterTransfer $transfer, User $actor, string $reason): ShelterTransfer
    {
        return DB::transaction(function () use ($transfer, $actor, $reason) {
            $transfer = $this->lock($transfer);
            $this->authorize($transfer->canBeRefusedBy($actor), 'refuse');

            $transfer->update([
                'status' => ShelterTransfer::REFUSED,
                'refused_by' => $actor->id,
                'refused_at' => now(),
                'refusal_reason' => $reason,
            ]);

            $summary = sprintf(
                'Transfer of %s from %s to %s was refused by %s. Reason: %s',
                $transfer->household?->household_code,
                $transfer->fromCenter?->name,
                $transfer->toCenter?->name,
                $actor->name,
                $reason
            );

            AuditLogger::log('updated', $transfer, $summary);

            // system_alerts.type is an enum with no 'transfer' value. Using
            // 'other' with an explicit source beats migrating the enum for one
            // extra label.
            SystemAlerter::raise(
                'Shelter transfer refused',
                $summary,
                'warning',
                'other',
                'TransferService::refuse'
            );

            return $transfer;
        });
    }

    /** Origin records the OUT time. The family is now on the road. */
    public function depart(ShelterTransfer $transfer, User $actor): ShelterTransfer
    {
        return DB::transaction(function () use ($transfer, $actor) {
            $transfer = $this->lock($transfer);
            $this->authorize($transfer->canBeDepartedBy($actor), 'record departure for');

            $transfer->update([
                'status' => ShelterTransfer::IN_TRANSIT,
                'departed_by' => $actor->id,
                'departed_at' => now(),
            ]);

            AuditLogger::log('updated', $transfer, sprintf(
                'Recorded departure of %s from %s (%d travelling)',
                $transfer->household?->household_code,
                $transfer->fromCenter?->name,
                (int) $transfer->members_expected
            ));

            return $transfer;
        });
    }

    /**
     * Destination records the IN time. THIS is the only point at which the
     * household actually moves and the only point at which occupancy changes.
     *
     * @param  array<int, int>  $presentMemberIds  the members who actually arrived
     */
    public function receive(ShelterTransfer $transfer, User $actor, array $presentMemberIds): ShelterTransfer
    {
        return DB::transaction(function () use ($transfer, $actor, $presentMemberIds) {
            $transfer = $this->lock($transfer);
            $this->authorize($transfer->canBeReceivedBy($actor), 'receive');

            $household = Household::whereKey($transfer->household_id)->lockForUpdate()->firstOrFail();
            $origin = EvacuationCenter::find($transfer->from_center_id);
            $destination = EvacuationCenter::findOrFail($transfer->to_center_id);

            // Only ids that really belong to this family, so a tampered form
            // cannot mark someone else's member present.
            $valid = $household->members()->whereIn('id', $presentMemberIds)->pluck('id')->all();

            if (empty($valid)) {
                $this->fail('present', 'Tick at least one person who arrived at the shelter.');
            }

            $household->members()->update(['is_present' => false]);
            $household->members()->whereIn('id', $valid)->update(['is_present' => true]);

            $present = $household->members()->where('is_present', true)->count();

            $household->update([
                'evacuation_center_id' => $destination->id,
                'status' => 'checked_in',
                // The arrival time at the shelter the family is in NOW, so
                // "checked in since" and the shelter list sort are both honest.
                // The time they arrived at the origin is preserved on this
                // transfer row as origin_checked_in_at.
                'checked_in_at' => now(),
                'checked_out_at' => null,
                'members_present' => $present,
            ]);

            // Derived, never incremented. Both ends, always, in this order.
            $origin?->recalcOccupancy();
            $destination->recalcOccupancy();

            $transfer->update([
                'status' => ShelterTransfer::COMPLETED,
                'received_by' => $actor->id,
                'received_at' => now(),
                'members_received' => $present,
            ]);

            $note = sprintf(
                'Received %s at %s (%d of %d expected arrived)',
                $household->household_code,
                $destination->name,
                $present,
                (int) $transfer->members_expected
            );

            AuditLogger::log('updated', $transfer, $note);
            AuditLogger::log('updated', $household, $note);

            // A short arrival is not an error, but it is worth a Super Admin
            // alert: it means people left the origin and are unaccounted for.
            if ($present < (int) $transfer->members_expected) {
                SystemAlerter::raise(
                    'Fewer evacuees arrived than departed',
                    sprintf(
                        '%s left %s with %d people but %d were received at %s.',
                        $household->household_code,
                        $transfer->fromCenter?->name,
                        (int) $transfer->members_expected,
                        $present,
                        $destination->name
                    ),
                    'warning',
                    'other',
                    'TransferService::receive'
                );
            }

            return $transfer;
        });
    }

    /**
     * Call the whole thing off. Nothing to recalculate: the household never
     * left the origin as far as the database is concerned.
     */
    public function cancel(ShelterTransfer $transfer, User $actor, ?string $reason = null): ShelterTransfer
    {
        return DB::transaction(function () use ($transfer, $actor, $reason) {
            $transfer = $this->lock($transfer);
            $this->authorize($transfer->canBeCancelledBy($actor), 'cancel');

            $wasInTransit = $transfer->status === ShelterTransfer::IN_TRANSIT;

            $transfer->update([
                'status' => ShelterTransfer::CANCELLED,
                'cancelled_by' => $actor->id,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ]);

            AuditLogger::log('updated', $transfer, sprintf(
                'Cancelled %stransfer of %s from %s to %s%s',
                $wasInTransit ? 'in-transit ' : '',
                $transfer->household?->household_code,
                $transfer->fromCenter?->name,
                $transfer->toCenter?->name,
                $reason ? '. Reason: ' . $reason : ''
            ));

            if ($wasInTransit) {
                SystemAlerter::raise(
                    'In-transit transfer cancelled',
                    sprintf(
                        '%s departed %s but never arrived at %s. The transfer was cancelled by %s. The household remains checked in at %s.',
                        $transfer->household?->household_code,
                        $transfer->fromCenter?->name,
                        $transfer->toCenter?->name,
                        $actor->name,
                        $transfer->fromCenter?->name
                    ),
                    'warning',
                    'other',
                    'TransferService::cancel'
                );
            }

            return $transfer;
        });
    }

    // -----------------------------------------------------------------
    // Queries used by both sides
    // -----------------------------------------------------------------

    /** The open transfer for a household, if any. Used as a guard everywhere. */
    public function openTransferFor(Household $household): ?ShelterTransfer
    {
        return ShelterTransfer::where('household_id', $household->id)
            ->open()
            ->latest('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $filters  status, direction, q
     */
    public function listQuery(User $user, array $filters = []): Builder
    {
        $query = ShelterTransfer::with([
            'household.headMember', 'fromCenter', 'toCenter',
            'requestedBy', 'confirmedBy', 'departedBy', 'receivedBy', 'refusedBy', 'cancelledBy',
        ])->visibleTo($user);

        $status = $filters['status'] ?? 'open';

        if ($status === 'open') {
            $query->open();
        } elseif ($status === 'overdue') {
            $query->overdue();
        } elseif ($status && $status !== 'all') {
            $query->where('status', $status);
        }

        // Direction only means something to staff with a roster. City Admin sees
        // every shelter, so there is no "in" or "out" from their position.
        $direction = $filters['direction'] ?? null;
        if ($direction && $user->isBarangayPersonnel()) {
            $ids = $user->assignedCenterIds()->all();
            if ($direction === 'incoming') {
                $query->whereIn('to_center_id', $ids);
            } elseif ($direction === 'outgoing') {
                $query->whereIn('from_center_id', $ids);
            }
        }

        if ($term = trim((string) ($filters['q'] ?? ''))) {
            $query->whereHas('household', fn ($q) => $q
                ->where('household_code', 'like', "%{$term}%")
                ->orWhereHas('members', fn ($m) => $m
                    ->where('is_household_head', true)
                    ->where('full_name', 'like', "%{$term}%")));
        }

        // Anything still moving floats to the top. Written as a CASE rather than
        // MySQL's FIELD() so the ordering also works on the sqlite copy of the
        // database that ships in the repo. This is an ORDER BY, never a GROUP BY.
        return $query
            ->orderByRaw("CASE status WHEN 'in_transit' THEN 1 WHEN 'pending' THEN 2 WHEN 'approved' THEN 3 ELSE 4 END")
            ->latest('id');
    }

    /**
     * Counts for the persistent alert bar and the sidebar glow.
     *
     * Computed ON READ, on every page load for the two staff layouts. One query
     * that fetches the OPEN transfers only -- there are never many, and folding
     * the counts in PHP keeps this off the ONLY_FULL_GROUP_BY minefield that
     * caught the age-tier work.
     *
     * @return array<string, int>
     */
    public function alertsFor(User $user): array
    {
        $open = ShelterTransfer::visibleTo($user)->open()->get();

        $awaitingConfirmation = 0;
        $awaitingDeparture = 0;
        $awaitingReceipt = 0;
        $overdue = 0;

        foreach ($open as $transfer) {
            if ($transfer->canBeConfirmedBy($user)) {
                $awaitingConfirmation++;
            }
            if ($transfer->canBeDepartedBy($user)) {
                $awaitingDeparture++;
            }
            if ($transfer->canBeReceivedBy($user)) {
                $awaitingReceipt++;
            }
            if ($transfer->isOverdue()) {
                $overdue++;
            }
        }

        return [
            'open' => $open->count(),
            'awaiting_confirmation' => $awaitingConfirmation,
            'awaiting_departure' => $awaitingDeparture,
            'awaiting_receipt' => $awaitingReceipt,
            'overdue' => $overdue,
            'needs_action' => $awaitingConfirmation + $awaitingDeparture + $awaitingReceipt,
        ];
    }

    /**
     * Every active shelter, as a plain array for the destination picker.
     *
     * The whole list is sent to the browser and the ORIGIN is filtered out in
     * JS, because on the Transfers page the origin is not known until a
     * household has been picked. An overcapacity shelter stays selectable -- in
     * a real evacuation there may be nowhere else to send people -- but the
     * picker labels it so the choice is deliberate.
     *
     * @return array<int, array<string, mixed>>
     */
    public function centerOptions(): array
    {
        return EvacuationCenter::with('barangay')
            ->where('status', 'active')
            ->orderBy('name')
            ->get()
            ->map(fn (EvacuationCenter $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'barangay' => $c->barangay?->name,
                'occupancy' => (int) $c->current_occupancy,
                'capacity' => (int) $c->capacity,
                'band' => $c->capacityBand(),
                'over' => $c->isOvercapacity(),
            ])
            ->values()
            ->all();
    }

    /**
     * Households that can be transferred, for the picker on the Transfers page.
     *
     * Deliberately NOT scoped to the user's roster. A destination shelter is
     * allowed to raise a transfer for a family currently sitting in a shelter it
     * has no assignment to -- that is the "pull them over to us" case the
     * permission rule explicitly allows. The authorisation check lives on the
     * transfer itself (origin OR destination), so widening the search does not
     * widen what anyone can actually do.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function searchHouseholds(string $term, int $limit = 10): Collection
    {
        return Household::with(['headMember', 'evacuationCenter.barangay'])
            ->where('status', 'checked_in')
            ->whereNotNull('evacuation_center_id')
            ->when($term, fn ($q) => $q
                ->where(fn ($inner) => $inner
                    ->where('household_code', 'like', "%{$term}%")
                    ->orWhereHas('members', fn ($m) => $m
                        ->where('is_household_head', true)
                        ->where('full_name', 'like', "%{$term}%"))))
            ->orderByDesc('checked_in_at')
            ->limit($limit)
            ->get()
            ->map(function (Household $h) {
                $open = ShelterTransfer::where('household_id', $h->id)->open()->exists();

                return [
                    'id' => $h->id,
                    'code' => $h->household_code,
                    'head' => $h->headMember?->full_name ?? '-',
                    'present' => (int) $h->members_present,
                    'center_id' => (int) $h->evacuation_center_id,
                    'center' => $h->evacuationCenter?->name,
                    'barangay' => $h->evacuationCenter?->barangay?->name,
                    'has_open_transfer' => $open,
                ];
            });
    }

    /**
     * Members for the arrival checklist, everyone currently marked present
     * pre-ticked.
     *
     * @return array<int, array<string, mixed>>
     */
    public function arrivalChecklist(ShelterTransfer $transfer): array
    {
        $household = $transfer->household;

        if (! $household) {
            return [];
        }

        return $household->members()
            ->orderByDesc('is_household_head')
            ->orderBy('full_name')
            ->get()
            ->map(fn ($m) => [
                'id' => $m->id,
                'name' => $m->full_name,
                'is_head' => (bool) $m->is_household_head,
                'is_present' => (bool) $m->is_present,
            ])
            ->values()
            ->all();
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * Re-read the row inside the transaction with a write lock, so two staff
     * clicking Confirm at the same moment cannot both see status = pending.
     */
    private function lock(ShelterTransfer $transfer): ShelterTransfer
    {
        return ShelterTransfer::whereKey($transfer->id)->lockForUpdate()->firstOrFail();
    }

    private function authorize(bool $allowed, string $verb): void
    {
        abort_if(! $allowed, 403, "You are not allowed to {$verb} this transfer, or it has already moved on.");
    }

    private function fail(string $field, string $message): void
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
