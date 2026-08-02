<?php

namespace App\Services;

use App\Models\Household;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * PHASE 5 ITEM 8b -- correcting who is physically present at a shelter.
 *
 * WHY THIS EXISTS: before this file there was NO path in the application that
 * could set household_members.is_present back to true. Check-in wrote it,
 * check-out cleared it, TransferService::receive() cleared it for anyone who did
 * not arrive -- and nothing could ever undo that. A family member who turned up
 * an hour after the rest of their family was unrecordable, and the destination
 * shelter's occupancy stayed wrong until somebody edited the database by hand.
 *
 * The transfer flow is what made the hole visible, but the hole is general: a
 * person who steps out for a day, a person who leaves for good, and a plain
 * miscount at the registration desk were all equally uncorrectable.
 *
 * WHY IT IS NOT A METHOD ON TransferService: that class states in its own
 * docblock that it owns every shelter-TRANSFER TRANSITION. This is not one. The
 * only transfer involvement here is a read-only guard, and folding presence
 * correction into that service would make its stated contract false for whoever
 * reads it next.
 *
 * WHY IT IS NOT PART OF HouseholdMemberSync: that service writes members and
 * tags from a submitted family-edit form and deliberately leaves is_present
 * alone on the edit path (its $keepPresence flag). Presence is a different
 * question from family composition, asked at a different moment by different
 * staff, and merging the two would mean a routine name correction could silently
 * move a shelter's headcount.
 *
 * OCCUPANCY CONTRACT, unchanged: members_present is recomputed from the
 * is_present ticks, then EvacuationCenter::recalcOccupancy() sums
 * members_present over checked-in households. Nothing here increments or
 * decrements anything.
 */
class PresenceService
{
    /**
     * The tick list shown in the Update Presence modal.
     *
     * Head first, then alphabetical -- the same ordering as
     * TransferService::arrivalChecklist(), so the two lists that staff see for
     * the same family never disagree about row order.
     *
     * @return array<int, array<string, mixed>>
     */
    public function checklist(Household $household): array
    {
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

    /**
     * Why this household cannot have its presence corrected right now, or null
     * when it can.
     *
     * Returned as a STRING rather than thrown, because the modal asks this
     * question before it renders the tick list: staff should be told "there is a
     * transfer in progress" instead of being shown a form that the server will
     * reject on submit. update() re-checks all of it anyway -- see the note
     * there.
     */
    public function blockedReason(Household $household, User $actor): ?string
    {
        if (! $actor->canAccessCenter($household->evacuation_center_id)) {
            return 'This household is at a shelter you are not assigned to.';
        }

        if ($household->status !== 'checked_in') {
            return 'Presence can only be corrected for a household that is currently checked in.';
        }

        // A pending transfer has already snapshotted members_expected. Letting
        // the headcount move underneath it would make the arrival comparison at
        // the destination lie about how many people set out. Same guard, and
        // deliberately the same wording, as the check-out path.
        if ($open = $household->openTransfer()) {
            return "This household has a shelter transfer in progress ({$open->statusLabel()}). "
                . 'Cancel or complete the transfer first.';
        }

        return null;
    }

    /**
     * Write the corrected presence for one household.
     *
     * @param  array<int, int>  $presentMemberIds  everyone ticked as present now
     */
    public function update(Household $household, array $presentMemberIds, User $actor): Household
    {
        return DB::transaction(function () use ($household, $presentMemberIds, $actor) {
            // Re-read under a write lock INSIDE the transaction. blockedReason()
            // was answered when the modal opened, which may have been minutes
            // ago; a transfer could have been raised for this family since, and
            // two staff members could be submitting this form at the same
            // moment. The guard that matters is this one.
            $household = Household::whereKey($household->id)->lockForUpdate()->firstOrFail();

            if ($blocked = $this->blockedReason($household, $actor)) {
                $this->fail('household', $blocked);
            }

            // Only ids that really belong to this family, so a tampered form
            // cannot mark somebody else's member present.
            $valid = $household->members()->whereIn('id', $presentMemberIds)->pluck('id')->all();

            if (empty($valid)) {
                // Zero present is a check-out, not a presence correction. Say so
                // and point at the control that does it, rather than quietly
                // emptying the household and leaving it checked in with nobody
                // in it.
                $this->fail('present', 'At least one person must stay ticked. '
                    . 'To record that the whole family has left, use Check-out instead.');
            }

            // Captured BEFORE the write so the audit entry can say what changed
            // rather than only what the state ended up as.
            $before = $household->members()
                ->where('is_present', true)
                ->pluck('full_name', 'id')
                ->all();

            $household->members()->update(['is_present' => false]);
            $household->members()->whereIn('id', $valid)->update(['is_present' => true]);

            $present = $household->members()->where('is_present', true)->count();

            $household->update(['members_present' => $present]);

            // Derived, never incremented.
            $center = $household->evacuationCenter;
            $center?->recalcOccupancy();

            $after = $household->members()
                ->where('is_present', true)
                ->pluck('full_name', 'id')
                ->all();

            AuditLogger::log('updated', $household, $this->describe(
                $household,
                $center?->name,
                $before,
                $after
            ));

            return $household;
        });
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * The audit description: who was added, who was removed, and the resulting
     * headcount. Names rather than ids, because an audit trail read six months
     * later by a CSWDO officer should not need a second query to be legible.
     *
     * @param  array<int, string>  $before
     * @param  array<int, string>  $after
     */
    private function describe(Household $household, ?string $centerName, array $before, array $after): string
    {
        $added = array_values(array_diff_key($after, $before));
        $removed = array_values(array_diff_key($before, $after));

        $note = sprintf(
            'Updated presence for %s at %s: %d present, was %d.',
            $household->household_code,
            $centerName ?? 'an unknown shelter',
            count($after),
            count($before)
        );

        if ($added) {
            $note .= ' Marked present: ' . implode('; ', $added) . '.';
        }

        if ($removed) {
            $note .= ' Marked not present: ' . implode('; ', $removed) . '.';
        }

        if (! $added && ! $removed) {
            $note .= ' No change to who is present.';
        }

        return $note;
    }

    private function fail(string $field, string $message): void
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
