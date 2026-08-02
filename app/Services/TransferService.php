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
     * @param  array<int|string, string>  $reasons  member id to absence reason code,
     *                                              REQUIRED for everyone not ticked
     */
    public function receive(
        ShelterTransfer $transfer,
        User $actor,
        array $presentMemberIds,
        array $reasons = []
    ): ShelterTransfer {
        return DB::transaction(function () use ($transfer, $actor, $presentMemberIds, $reasons) {
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

            // PHASE 5 ITEM 8b. An absence is someone who WAS present at the
            // origin and is not ticked on arrival. Scoped to is_present = true
            // deliberately: a member who was already absent before the transfer
            // (registered with the family but never in the shelter) was never on
            // the truck, so asking staff why they "did not arrive" would be
            // asking about a journey that person never started.
            //
            // is_present still holds the ORIGIN state at this point, which is why
            // this is built BEFORE the writes below. After them nobody could tell
            // an arrival from an absence.
            $absent = $household->members()
                ->where('is_present', true)
                ->whereNotIn('id', $valid)
                ->pluck('full_name', 'id')
                ->all();

            $didNotArrive = [];
            foreach ($absent as $memberId => $memberName) {
                $code = $reasons[$memberId] ?? $reasons[(string) $memberId] ?? null;

                if (! array_key_exists($code, ShelterTransfer::ABSENCE_REASONS)) {
                    $this->fail('reasons',
                        "Choose a reason for {$memberName}, who is not ticked as arrived.");
                }

                $didNotArrive[] = [
                    'member_id' => (int) $memberId,
                    'reason' => $code,
                    // Filled in later by resolveAbsence(). Present from the start
                    // so every entry has the same shape and nothing has to guard
                    // for a missing key.
                    'resolved' => null,
                    'resolved_by' => null,
                    'resolved_at' => null,
                ];
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
                'did_not_arrive' => $didNotArrive ?: null,
            ]);

            $note = sprintf(
                'Received %s at %s (%d of %d expected arrived)',
                $household->household_code,
                $destination->name,
                $present,
                (int) $transfer->members_expected
            );

            if ($didNotArrive) {
                $note .= ' Did not arrive: ' . $this->describeAbsences($didNotArrive, $absent) . '.';
            }

            AuditLogger::log('updated', $transfer, $note);
            AuditLogger::log('updated', $household, $note);

            // A short arrival is not an error, but it is worth a Super Admin
            // alert: it means people left the origin and are unaccounted for.
            //
            // Reaches Super Admins ONLY, by email, and only where
            // EVACTECH_ALERT_EMAILS is configured. It is NOT a City Admin
            // channel -- what City Admin has to see lives in the alert bar and
            // on the Transfers page.
            if ($present < (int) $transfer->members_expected) {
                SystemAlerter::raise(
                    'Fewer evacuees arrived than departed',
                    sprintf(
                        '%s left %s with %d people but %d were received at %s.%s',
                        $household->household_code,
                        $transfer->fromCenter?->name,
                        (int) $transfer->members_expected,
                        $present,
                        $destination->name,
                        // ITEM 8b section 4.6: name them and give the reasons.
                        // A count on its own is not something anybody can act on.
                        $didNotArrive
                            ? ' Did not arrive: ' . $this->describeAbsences($didNotArrive, $absent) . '.'
                            : ''
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
     * PHASE 5 ITEM 8b -- record what happened to someone who did not arrive.
     *
     * Two different things behind one control:
     *
     *  - ARRIVED delegates to PresenceService. Nothing is written to the
     *    transfer row, because arrival is carried by is_present alone. If
     *    presence is later corrected back to absent the person correctly
     *    reappears as unaccounted for.
     *  - Everything else is a record-only resolution. It changes NO counts. The
     *    person was already absent from members_present and from occupancy; the
     *    only thing that changes is that the system stops asking.
     *
     * The reason recorded at receipt is NEVER overwritten. At that moment, on
     * that hop, the desk honestly did not know -- that stays true, and the row
     * keeps saying "3 of 4 arrived".
     */
    public function resolveAbsence(
        ShelterTransfer $transfer,
        User $actor,
        int $memberId,
        string $resolution
    ): ShelterTransfer {
        if ($resolution === ShelterTransfer::RESOLUTION_ARRIVED) {
            // Not inside the transaction below: PresenceService opens its own,
            // takes its own locks and writes its own audit entry. Nesting them
            // would give one action two overlapping locks on the same household
            // for no benefit.
            $household = Household::findOrFail($transfer->household_id);

            $this->authorize(
                in_array($memberId, $this->absentMemberIds($transfer), true),
                'resolve'
            );

            $present = $household->members()
                ->where('is_present', true)
                ->pluck('id')
                ->push($memberId)
                ->unique()
                ->all();

            // PresenceService checks access to the shelter the family is in NOW,
            // which after a further transfer may be neither end of this row.
            app(PresenceService::class)->update($household, $present, $actor);

            return $transfer->refresh();
        }

        return DB::transaction(function () use ($transfer, $actor, $memberId, $resolution) {
            $transfer = $this->lock($transfer);
            $this->authorize($transfer->canResolveAbsenceBy($actor), 'resolve');

            if (! array_key_exists($resolution, ShelterTransfer::RECORDED_RESOLUTIONS)) {
                $this->fail('resolution', 'Choose what happened to this person.');
            }

            $entries = $transfer->did_not_arrive;
            if (! is_array($entries) || empty($entries)) {
                $this->fail('resolution', 'This transfer has no absence to resolve.');
            }

            $found = false;
            foreach ($entries as $i => $entry) {
                if ((int) ($entry['member_id'] ?? 0) !== $memberId) {
                    continue;
                }
                $entries[$i]['resolved'] = $resolution;
                $entries[$i]['resolved_by'] = $actor->id;
                $entries[$i]['resolved_at'] = now()->toDateTimeString();
                $found = true;
            }

            if (! $found) {
                $this->fail('resolution', 'That person is not on this transfer\'s absence list.');
            }

            $transfer->update(['did_not_arrive' => $entries]);

            $name = $transfer->household?->members()->whereKey($memberId)->value('full_name') ?? 'a member';

            AuditLogger::log('updated', $transfer, sprintf(
                'Recorded %s as "%s" after transfer of %s. No headcount change: this person was '
                . 'already not counted present at the shelter.',
                $name,
                ShelterTransfer::resolutionLabel($resolution),
                $transfer->household?->household_code ?? 'a household'
            ));

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
            // PHASE 5 ITEM 8b: household.members is loaded so the table can print
            // the NAMES of people who did not arrive. Without it, every row would
            // fire its own query for a handful of names.
            'household.headMember', 'household.members', 'fromCenter', 'toCenter',
            'requestedBy', 'confirmedBy', 'departedBy', 'receivedBy', 'refusedBy', 'cancelledBy',
        ])->visibleTo($user);

        $status = $filters['status'] ?? 'open';

        if ($status === 'open') {
            $query->open();
        } elseif ($status === 'overdue') {
            $query->overdue();
        } elseif ($status === 'unaccounted') {
            // PHASE 5 ITEM 8b. Not a real status, like 'open' and 'overdue'
            // before it. The id set is computed first and applied with whereIn
            // rather than filtered after the fact, because filtering a paginated
            // result in PHP gives wrong page counts.
            $query->whereIn('id', $this->unaccountedTransferIds($user));
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

        // PHASE 5 ITEM 8b. A SECOND query, because unaccounted-for people live on
        // COMPLETED transfers, which the open() scope above excludes by
        // definition. Counted in PEOPLE rather than transfers: that is the unit
        // staff and CSWDO think in, and it matches the count on the capacity
        // panel, so the same idea does not carry two units one screen apart.
        $unaccounted = $this->unaccountedRows(
            ShelterTransfer::visibleTo($user)
        );

        return [
            'open' => $open->count(),
            'awaiting_confirmation' => $awaitingConfirmation,
            'awaiting_departure' => $awaitingDeparture,
            'awaiting_receipt' => $awaitingReceipt,
            'overdue' => $overdue,
            'needs_action' => $awaitingConfirmation + $awaitingDeparture + $awaitingReceipt,
            'unaccounted_people' => $unaccounted['people'],
            'unaccounted_transfers' => $unaccounted['transfers']->count(),
        ];
    }

    /**
     * PHASE 5 ITEM 8b -- ids of transfers that currently have somebody
     * unaccounted for, for the Transfers-page filter.
     *
     * @return array<int, int>
     */
    public function unaccountedTransferIds(User $user): array
    {
        return $this->unaccountedRows(ShelterTransfer::visibleTo($user))['transfers']->all();
    }

    /**
     * PHASE 5 ITEM 8b -- how many people this ONE shelter has not accounted for.
     *
     * Scoped by where the household is NOW, not by which end of the transfer
     * this shelter was. A family received here and then moved on is somebody
     * else's reconciliation.
     */
    public function unaccountedCountFor(EvacuationCenter $center): int
    {
        return $this->unaccountedRows(
            ShelterTransfer::query(),
            fn ($q) => $q->where('evacuation_center_id', $center->id)
        )['people'];
    }

    /**
     * The one fold that every unaccounted-for figure comes from.
     *
     * WHY A FOLD RATHER THAN SQL: the set is stored as JSON, and the JSON
     * functions that could filter it in the database are MySQL-only. The repo
     * ships an sqlite copy, which is the same reason listQuery() orders with a
     * CASE instead of FIELD().
     *
     * The cost is bounded on purpose. The whereHas clause is BOTH half of the
     * derived rule (section 4.4 requires the household to still be checked in)
     * AND the thing that stops this growing without limit across a long
     * disaster: families check out, and when they do they leave this set.
     *
     * @param  callable|null  $householdFilter  extra constraint on the household
     * @return array{transfers: Collection, people: int}
     */
    private function unaccountedRows(Builder $base, ?callable $householdFilter = null): array
    {
        $rows = $base
            ->where('status', ShelterTransfer::COMPLETED)
            ->whereNotNull('did_not_arrive')
            ->whereHas('household', function ($q) use ($householdFilter) {
                $q->where('status', 'checked_in');
                if ($householdFilter) {
                    $householdFilter($q);
                }
            })
            ->with('household.members')
            ->get();

        $memberIds = [];
        $transferIds = [];

        foreach ($rows as $row) {
            $ids = $row->unaccountedMemberIds();
            if (empty($ids)) {
                continue;
            }
            $transferIds[] = $row->id;
            foreach ($ids as $id) {
                $memberIds[$id] = true;
            }
        }

        return [
            'transfers' => collect($transferIds),
            // Distinct people: someone could in principle appear on two hops.
            'people' => count($memberIds),
        ];
    }

    /**
     * "Dela Cruz, Maria (Unknown); Santos, Jose (Returned home)" for the audit
     * entry and the Super Admin alert.
     *
     * @param  array<int, array<string, mixed>>  $didNotArrive
     * @param  array<int, string>  $names  member id to full name
     */
    private function describeAbsences(array $didNotArrive, array $names): string
    {
        $parts = [];

        foreach ($didNotArrive as $entry) {
            $id = (int) ($entry['member_id'] ?? 0);
            $parts[] = sprintf(
                '%s (%s)',
                $names[$id] ?? 'a member',
                ShelterTransfer::absenceReasonLabel($entry['reason'] ?? null)
            );
        }

        return implode('; ', $parts);
    }

    /**
     * Member ids recorded as not having arrived on this transfer, whatever the
     * reason. Used to check that a Resolve request names somebody who really is
     * on this row's absence list.
     *
     * @return array<int, int>
     */
    private function absentMemberIds(ShelterTransfer $transfer): array
    {
        $entries = $transfer->did_not_arrive;

        if (! is_array($entries)) {
            return [];
        }

        return array_values(array_map(fn ($e) => (int) ($e['member_id'] ?? 0), $entries));
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
