<?php

namespace App\Services;

use App\Models\EvacuationCenter;
use App\Models\Household;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * SEPARATED HOUSEHOLDS, DROP 2 -- reunification.
 *
 * A household declared "separated from their family" at registration reaches
 * the shelter where that family is sheltering. Two household records now sit in
 * one building describing one family. This service collapses them into one.
 *
 * NOTHING IS MATCHED BY NAME, ANYWHERE. The previous design inferred the link
 * from names and could not be made to work -- exact matching missed a "similar"
 * name, and loosening it would have proposed merging strangers. Here the
 * operator declares the family at registration and identifies the stale
 * placeholder rows at reunification. Both ends are human statements, made by
 * someone standing in front of the family.
 *
 * DIRECTION: the FAMILY household survives; the separated household dissolves.
 * The family holds the head, the household code it has been identified by since
 * registration, and usually more members.
 *
 * WHICH RECORD WINS: the NEWLY REGISTERED one. It was captured with the person
 * standing there, so it is the most current. Where the family had already
 * listed that person, their old row is a stale placeholder and is deleted.
 *
 * THE COST OF THAT, AND THE ONE PROTECTION. member_vulnerabilities is
 * cascadeOnDelete on household_member_id, so deleting a placeholder destroys any
 * vulnerability tags recorded on it -- and those tags drive relief
 * prioritisation. Where the pairing is unambiguous (one arriving member, one
 * placeholder identified) blanks and missing tags are carried across first.
 * Newest still wins wherever both gave an answer; the old record only fills
 * gaps. Where several people arrive at once there is no safe pairing, so the
 * placeholders go as specified.
 *
 * NO COUNTING CHANGE ANYWHERE. Separated households count as their own affected
 * family at their own shelter and city-wide totals do not deduplicate. Single
 * Headed Household -- five hand-written copies, one of them raw SQL -- is not
 * touched by this file.
 */
class ReunificationService
{
    public function __construct(private HouseholdMemberSync $sync)
    {
    }

    /**
     * Separated households currently checked in at this shelter.
     *
     * The panel that renders these is ALWAYS shown, empty or not. A queue that
     * appears only when it has rows makes "nobody is separated here" and "this
     * feature does not exist" look identical, which cost a full debugging round
     * once already.
     */
    public function pendingAt(EvacuationCenter $center)
    {
        return Household::query()
            ->where('evacuation_center_id', $center->id)
            ->where('status', 'checked_in')
            ->where('is_separated', true)
            ->with(['members', 'headMember', 'actingHeadMember'])
            ->orderBy('household_code')
            ->get();
    }

    /**
     * Households at this shelter that could be the family, excluding the
     * fragment itself and any other separated fragment.
     *
     * Deliberately every household at the shelter rather than a shortlist. The
     * operator knows which family this is; the system does not, and any attempt
     * to rank them would be the name matching this design exists to remove.
     */
    public function familyOptionsAt(EvacuationCenter $center, Household $fragment)
    {
        return Household::query()
            ->where('evacuation_center_id', $center->id)
            ->where('status', 'checked_in')
            ->where('id', '!=', $fragment->id)
            ->where('is_separated', false)
            ->with(['members', 'headMember', 'actingHeadMember'])
            ->orderBy('household_code')
            ->get();
    }

    /** Why these two cannot be reunited, or null when they can. */
    public function blockedReason(Household $fragment, Household $family): ?string
    {
        if ((int) $fragment->id === (int) $family->id) {
            return 'A household cannot be reunited with itself.';
        }

        if (! $fragment->is_separated) {
            return 'That household is not marked as separated from a family.';
        }

        if ($fragment->status !== 'checked_in' || $family->status !== 'checked_in') {
            return 'Both households must be checked in.';
        }

        if ((int) $fragment->evacuation_center_id !== (int) $family->evacuation_center_id) {
            return 'Both households must be at the same shelter. Transfer one of them first.';
        }

        if ($fragment->members()->count() === 0) {
            return 'That household has no members to move.';
        }

        return null;
    }

    /**
     * Perform the merge.
     *
     * $stalePlaceholderIds are member rows INSIDE THE FAMILY that the operator
     * has identified as the same people who are arriving -- rows the family
     * filled in from memory while these people were elsewhere.
     *
     * @param array<int, int> $stalePlaceholderIds
     */
    public function reunite(
        Household $fragment,
        Household $family,
        array $stalePlaceholderIds,
        User $actor
    ): void {
        $reason = $this->blockedReason($fragment, $family);
        if ($reason !== null) {
            throw new \RuntimeException($reason);
        }

        DB::transaction(function () use ($fragment, $family, $stalePlaceholderIds, $actor) {
            $fragment = $fragment->fresh();
            $family = $family->fresh();

            // Only rows genuinely inside the family can be treated as its
            // placeholders. A posted id from anywhere else is discarded rather
            // than trusted.
            $stale = $family->members()
                ->whereIn('id', $stalePlaceholderIds)
                ->get();

            $arriving = $fragment->members()->get();
            $shelter = $family->evacuationCenter;

            /* THE UNAMBIGUOUS CASE. One person arriving, one placeholder named:
               the pairing is certain, so the record being deleted can donate
               what the kept record lacks. Anything the new registration
               answered wins; the old row only fills blanks. */
            if ($arriving->count() === 1 && $stale->count() === 1) {
                $this->carryOver($stale->first(), $arriving->first());
            }

            $staleNames = $stale->pluck('full_name')->all();
            $movedNames = $arriving->pluck('full_name')->all();
            $fragmentCode = $fragment->household_code;

            // Delete the placeholders BEFORE moving anyone in, so the family
            // never momentarily holds two rows for one person.
            foreach ($stale as $row) {
                $row->delete();
            }

            /* Move everyone across, preserving whatever is_present each row
               carries. is_household_head is forced false: the family already
               has a head and must not end up with two. */
            $fragment->members()->update([
                'household_id' => $family->id,
                'is_household_head' => false,
            ]);

            /* Null BOTH head facts on the shell before anything reads it. A
               dangling head_member_id renders a blank name on every roster that
               reads headMember(). */
            $fragment->update([
                'head_member_id' => null,
                'acting_head_member_id' => null,
                'number_of_members' => 0,
                'members_present' => 0,
                'status' => 'checked_out',
                'checked_out_at' => now(),
            ]);

            $family->update([
                'number_of_members' => $family->members()->count(),
                'members_present' => $family->members()->where('is_present', true)->count(),
            ]);

            // One shelter, so one recalculation -- and by counting, never by
            // arithmetic.
            $shelter?->recalcOccupancy();

            $note = 'Reunification: ' . implode(', ', $movedNames)
                . " moved from household {$fragmentCode} into {$family->household_code}"
                . ($shelter ? " at {$shelter->name}" : '') . '. '
                . ($staleNames
                    ? 'Replaced earlier entries for ' . implode(', ', $staleNames) . '. '
                    : '')
                . "Household {$fragmentCode} is now closed.";

            AuditLogger::log('updated', $family, $note);
            AuditLogger::log('updated', $fragment, $note);
        });
    }

    /**
     * Fill gaps on the kept record from the one about to be deleted.
     *
     * Only gaps. The newly registered row is the more current statement and
     * wins wherever it gave an answer.
     */
    private function carryOver($stale, $kept): void
    {
        $fill = [];

        foreach (['birthdate', 'age', 'age_tier_fallback', 'sex', 'contact_number', 'family_role'] as $field) {
            $mine = $kept->{$field};
            $theirs = $stale->{$field};
            if (($mine === null || $mine === '') && $theirs !== null && $theirs !== '') {
                $fill[$field] = $theirs;
            }
        }

        // A birthdate always beats a hand-picked age group, here as everywhere.
        if (array_key_exists('birthdate', $fill)) {
            $fill['age_tier_fallback'] = null;
        }

        if ($fill) {
            $kept->update($fill);
        }

        /* Tags are a UNION, not a replacement, and that is a deliberate
           asymmetry with the fields above. A vulnerability the family recorded
           and the new registration simply did not re-tick is far more likely an
           omission than a correction, and losing a PWD or pregnancy
           classification changes what relief that person is prioritised for.

           Routed through HouseholdMemberSync::applyTags(), the ONE write path,
           so female-only categories are still stripped from a member whose sex
           does not permit them. */
        $existing = $kept->vulnerabilities()->pluck('vulnerable_classification_id')->all();
        $donated = $stale->vulnerabilities()->pluck('vulnerable_classification_id')->all();
        $union = array_values(array_unique(array_merge($existing, $donated)));

        if ($union !== $existing) {
            $this->sync->applyTags($kept->fresh(), $union);
        }
    }
}
