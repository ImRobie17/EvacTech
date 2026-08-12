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
        $actingId = $household->acting_head_member_id;

        return $household->members()
            ->orderByDesc('is_household_head')
            ->orderBy('full_name')
            ->get()
            ->map(fn ($m) => [
                'id' => $m->id,
                'name' => $m->full_name,
                'is_head' => (bool) $m->is_household_head,
                'is_present' => (bool) $m->is_present,
                /* PHASE 9 ITEM 2. Without this the list would mark the
                   SUBSTANTIVE head "(head)" while the panel above it said
                   somebody else had been standing in as head -- two true
                   statements that read as a contradiction. Both are labelled. */
                'is_acting_head' => $actingId !== null && (int) $m->id === (int) $actingId,
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
     * PHASE 9 ITEM 2: $actingHeadChoice resolves a standing-in head, and it is
     * handled HERE rather than in the two role controllers that call this,
     * because an acting head is only ever resolved as a consequence of a
     * presence change. Written in both controllers it would be the same rule in
     * two places, which this codebase has already watched drift once -- the
     * check-in guard that diverged between barangay and City Admin. One
     * implementation, one transaction, one audit sentence.
     *
     * @param  array<int, int>  $presentMemberIds  everyone ticked as present now
     * @param  string|null      $actingHeadChoice  'revert' or 'keep'; null means
     *                                             no choice was offered
     */
    public function update(
        Household $household,
        array $presentMemberIds,
        User $actor,
        ?string $actingHeadChoice = null
    ): Household {
        return DB::transaction(function () use ($household, $presentMemberIds, $actor, $actingHeadChoice) {
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

            // PHASE 9 ITEM 2. Runs after the ticks are written, so it decides
            // against the state that was just saved rather than the state the
            // modal was opened on.
            $headNote = $this->resolveActingHead($household, $actingHeadChoice);

            AuditLogger::log('updated', $household, $this->describe(
                $household,
                $center?->name,
                $before,
                $after
            ) . $headNote);

            return $household;
        });
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * PHASE 9 ITEM 2 -- decide what happens to a standing-in head now that
     * presence has changed. Returns the sentence to append to the audit note,
     * or an empty string when nothing about the head changed.
     *
     * Three cases, in this order, and the order matters:
     *
     * 1. The STAND-IN is no longer present. Clear it. A stand-in who has left
     *    the shelter cannot be the person answerable for the family, and
     *    leaving the column set would name someone who is not there. Falling
     *    back to the substantive head is the honest state even if that person is
     *    also absent -- the record then says what it said before check-in.
     *
     * 2. The SUBSTANTIVE head is now present. Offer honoured: 'revert' clears
     *    the stand-in, anything else keeps it.
     *
     * 3. Neither -- the head is still absent and the stand-in is still here.
     *    Nothing to decide.
     *
     * DEFAULTS TO KEEPING. A null choice means the screen never asked, and
     * silently handing the role back on a routine presence correction would be a
     * state change nobody requested. Reverting is always an explicit act.
     *
     * Note this NEVER touches head_member_id or is_household_head. The
     * substantive head has not changed; only the stand-in has been cleared.
     * Permanently changing who the head is remains transferHead()'s job.
     */
    private function resolveActingHead(Household $household, ?string $choice): string
    {
        if (! $household->acting_head_member_id) {
            return '';
        }

        $acting = $household->members()
            ->whereKey($household->acting_head_member_id)
            ->first();

        $substantive = $household->head_member_id
            ? $household->members()->whereKey($household->head_member_id)->first()
            : null;

        $actingName = $acting?->full_name ?? 'The stand-in head';
        $headName = $substantive?->full_name ?? 'the household head';

        // Case 1 -- the stand-in has gone.
        if (! $acting || ! $acting->is_present) {
            $household->update(['acting_head_member_id' => null]);

            return " {$actingName} is no longer present, so the stand-in head designation was cleared"
                . " and {$headName} is again recorded as head.";
        }

        // Case 2 -- the real head is back.
        if ($substantive && $substantive->is_present) {
            if ($choice === 'revert') {
                $household->update(['acting_head_member_id' => null]);

                return " {$headName} has arrived and was restored as head;"
                    . " {$actingName} is no longer standing in.";
            }

            return " {$headName} has arrived, and staff chose to keep {$actingName}"
                . ' standing in as head until check-out.';
        }

        // Case 3 -- nothing to decide.
        return '';
    }

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
