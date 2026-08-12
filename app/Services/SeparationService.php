<?php

namespace App\Services;

use App\Models\EvacuationCenter;
use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\SeparatedMemberLink;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * PHASE 10A -- separated family members: detection, flagging and the move.
 *
 * THE SCENARIO. A family member who was elsewhere when the disaster struck
 * evacuates to the nearest shelter and was NEVER with the family at any
 * shelter. She is registered at shelter B; her family is at shelter A and has
 * already listed her among their members. One person, two rows.
 *
 * THIS SERVICE OWNS EVERY TRANSITION, exactly as TransferService owns every
 * shelter-transfer transition. Compose calls to it; never reproduce the move
 * inline. The move touches two households at two shelters and both occupancy
 * figures, and a second copy of it will drift -- see gotcha 19, which is not
 * hypothetical here: the single-headed rule already exists in five places.
 *
 * WHAT THIS SERVICE IS NOT. It is not reunification. Bringing a separated
 * member back to her family is a shelter transfer and is Phase 10B, through the
 * existing module. Nothing here moves a household.
 */
class SeparationService
{
    /**
     * EXACT-name duplicate detection.
     *
     * DELIBERATELY NOT HouseholdMember::scopeNameMatches(). That scope is a
     * LIKE %term% built for a human typing a fragment into a search box, where
     * a generous match costs the operator nothing but a longer list. This is a
     * different question with a different failure cost: a loose match here
     * proposes merging two people's records, so it demands the whole name and
     * nothing less. The two rules are not interchangeable and must not be
     * consolidated later on the grounds that both "search by name".
     *
     * Case-insensitive and whitespace-normalised, matching the exact-match
     * shape FindFamilyController has used since Phase 4. Names are compared in
     * PHP rather than by a self-join so the comparison rule stays visible in
     * one readable place; the dataset is one city's shelters, not a national
     * register.
     *
     * SCOPE. Only members of households CHECKED IN at $center are offered as
     * the "present" side -- a suggestion the operator cannot act on is noise.
     * The "family" side is any household at any OTHER shelter, because that is
     * precisely the case the feature exists for.
     *
     * @return array<int, array{present: HouseholdMember, family: HouseholdMember}>
     */
    public function candidatesFor(EvacuationCenter $center): array
    {
        $present = HouseholdMember::query()
            ->whereHas('household', fn ($q) => $q
                ->where('status', 'checked_in')
                ->where('evacuation_center_id', $center->id)
                ->whereNull('separated_from_household_id'))
            ->with('household')
            ->get();

        if ($present->isEmpty()) {
            return [];
        }

        $keys = $present->map(fn ($m) => $this->nameKey($m->full_name))
            ->filter()
            ->unique()
            ->values();

        if ($keys->isEmpty()) {
            return [];
        }

        // Everyone ELSEWHERE carrying one of those names. whereIn on the raw
        // lowered name is bound, not interpolated.
        $elsewhere = HouseholdMember::query()
            ->whereHas('household', fn ($q) => $q
                ->whereNotNull('evacuation_center_id')
                ->where('evacuation_center_id', '!=', $center->id))
            ->whereIn(DB::raw('LOWER(TRIM(full_name))'), $keys->all())
            ->with(['household.evacuationCenter', 'household.members'])
            ->get();

        if ($elsewhere->isEmpty()) {
            return [];
        }

        $known = SeparatedMemberLink::knownPairs();
        $out = [];

        foreach ($present as $p) {
            $key = $this->nameKey($p->full_name);
            if ($key === '') {
                continue;
            }

            foreach ($elsewhere as $f) {
                if ($this->nameKey($f->full_name) !== $key) {
                    continue;
                }
                // Same household on both sides is not a separation, it is a
                // duplicate row inside one family and is not this feature's job.
                if ((int) $f->household_id === (int) $p->household_id) {
                    continue;
                }
                if (in_array($f->id . '-' . $p->id, $known, true)) {
                    continue; // already flagged, confirmed or ruled out
                }

                $out[] = ['present' => $p, 'family' => $f];
            }
        }

        return $out;
    }

    /** Lower-cased, whitespace-collapsed name, or '' when there is nothing to compare. */
    private function nameKey(?string $name): string
    {
        return trim(preg_replace('/\s+/', ' ', mb_strtolower((string) $name)));
    }

    /**
     * Barangay staff raise a suspicion. No record moves here.
     *
     * Returns the link, or null when the pair is already on file. Idempotent on
     * purpose: two operators looking at the same roster during a surge will both
     * press the button, and the second press must not 500 on a unique-key
     * violation.
     */
    public function flag(HouseholdMember $familyMember, HouseholdMember $presentMember, User $actor): ?SeparatedMemberLink
    {
        $existing = SeparatedMemberLink::where('family_member_id', $familyMember->id)
            ->where('present_member_id', $presentMember->id)
            ->first();

        if ($existing) {
            return null;
        }

        $link = SeparatedMemberLink::create([
            'family_member_id' => $familyMember->id,
            'family_household_id' => $familyMember->household_id,
            'present_member_id' => $presentMember->id,
            'present_household_id' => $presentMember->household_id,
            'status' => SeparatedMemberLink::STATUS_PENDING,
            'flagged_by' => $actor->id,
        ]);

        AuditLogger::log('flagged', $link,
            "Flagged {$presentMember->full_name} as a possible separated member of household "
            . ($familyMember->household->household_code ?? 'unknown'));

        return $link;
    }

    /**
     * Why this link cannot be confirmed yet, or null when it can be.
     *
     * ITEM 7 -- HEAD PROTECTION, AT THE FAMILY END. Moving the head out of a
     * household would leave the family without one, and Phase 9 made that
     * silently self-repairing in a way that is wrong here: the next edit of
     * that household runs HouseholdMemberSync::sync(), which defaults row 0 to
     * head when nothing is flagged. The family would acquire a new head by
     * array position, chosen by nobody. So we refuse, and say what to do first.
     *
     * There are THREE head-ish facts since Phase 9 and this checks two of them
     * at the family end -- head_member_id (the substantive head) and
     * acting_head_member_id (the Phase 9 stand-in). is_household_head is the
     * third and is kept in step with head_member_id by sync(), so testing the
     * column on the household is sufficient and avoids disagreeing with it.
     *
     * The PRESENT end needs no such guard: that row is deleted and the moved
     * row inherits its headship in confirm() below.
     */
    public function blockedReason(SeparatedMemberLink $link): ?string
    {
        if (! $link->isPending()) {
            return 'This suggestion has already been decided.';
        }

        $familyMember = $link->familyMember;
        $presentMember = $link->presentMember;
        $familyHousehold = $link->familyHousehold;
        $presentHousehold = $link->presentHousehold;

        // Any of these can be null: member rows cascade, households do not, and
        // users soft-delete (gotcha 13). A queue must never offer a button that
        // fatals on a null relation.
        if (! $familyMember || ! $presentMember || ! $familyHousehold || ! $presentHousehold) {
            return 'One of these records no longer exists.';
        }

        if ((int) $familyMember->household_id === (int) $presentMember->household_id) {
            return 'Both records are already in the same household.';
        }

        if ((int) $familyHousehold->head_member_id === (int) $familyMember->id) {
            return 'This person is the head of their family. Designate another head first, then confirm.';
        }

        if ((int) $familyHousehold->acting_head_member_id === (int) $familyMember->id) {
            return 'This person is the acting head of their family. Resolve the acting head first, then confirm.';
        }

        if ($presentHousehold->separated_from_household_id !== null) {
            return 'That household is already linked to another family.';
        }

        return null;
    }

    /**
     * City Admin confirms. THIS IS THE MOVE.
     *
     * DIRECTION (b): the FAMILY row is moved into the present household and the
     * present row is deleted. The family row is the better record -- it was
     * entered with the household, unhurried, and usually carries a birthdate
     * and vulnerability tags. Crucially, member_vulnerabilities keys on
     * household_member_id, which does not change when household_id does, so the
     * tags follow the move for free. The other direction would have
     * cascade-deleted them with no way back.
     *
     * Blanks on the family row are filled from the present row first, so
     * anything shelter B captured that shelter A lacked survives. Only blanks:
     * the family's own answer wins wherever it gave one.
     *
     * OCCUPANCY IS DERIVED AT BOTH ENDS. members_present is recomputed by
     * counting, never incremented, and recalcOccupancy() runs at both shelters.
     */
    public function confirm(SeparatedMemberLink $link, User $actor): void
    {
        $reason = $this->blockedReason($link);
        if ($reason !== null) {
            throw new \RuntimeException($reason);
        }

        DB::transaction(function () use ($link, $actor) {
            $familyMember = $link->familyMember->fresh();
            $presentMember = $link->presentMember->fresh();
            $familyHousehold = $link->familyHousehold->fresh();
            $presentHousehold = $link->presentHousehold->fresh();

            $movedName = $familyMember->full_name;
            $presentWasHead = (int) $presentHousehold->head_member_id === (int) $presentMember->id;
            $presentWasActing = (int) $presentHousehold->acting_head_member_id === (int) $presentMember->id;

            // ---- 1. Fold the present row's blanks into the family row ----
            $fill = [];
            foreach (['birthdate', 'age', 'age_tier_fallback', 'sex', 'contact_number', 'family_role'] as $field) {
                $mine = $familyMember->{$field};
                $theirs = $presentMember->{$field};
                if (($mine === null || $mine === '') && $theirs !== null && $theirs !== '') {
                    $fill[$field] = $theirs;
                }
            }
            // A birthdate always wins over a manually picked age group, exactly
            // as it does everywhere else in the system.
            if (array_key_exists('birthdate', $fill)) {
                $fill['age_tier_fallback'] = null;
            }

            // ---- 2. THE MOVE. One UPDATE. Tags ride along on member id. ----
            $fill['household_id'] = $presentHousehold->id;
            $fill['is_present'] = true;
            // She is not the head of the family she is being moved out of, and
            // headship of the household she joins is decided just below.
            $fill['is_household_head'] = false;
            $familyMember->update($fill);

            // ---- 3. Delete the thin duplicate. Its tags cascade with it. ----
            $presentMember->delete();

            // ---- 4. Repoint headship at the destination if it pointed at the
            //         row we just deleted. Without this the fragment household
            //         has a dangling head_member_id and every roster that reads
            //         headMember() renders a blank name.
            $presentUpdates = [
                'separated_from_household_id' => $familyHousehold->id,
            ];
            if ($presentWasHead) {
                $presentUpdates['head_member_id'] = $familyMember->id;
                $familyMember->update(['is_household_head' => true]);
            }
            if ($presentWasActing) {
                $presentUpdates['acting_head_member_id'] = $familyMember->id;
            }
            $presentHousehold->update($presentUpdates);

            // ---- 5. Recount BOTH ends. Derived, never accumulated. ----
            $this->recount($familyHousehold);
            $this->recount($presentHousehold);

            $familyHousehold->evacuationCenter?->recalcOccupancy();
            $presentHousehold->evacuationCenter?->recalcOccupancy();

            // ---- 6. Close the link ----
            $link->update([
                'status' => SeparatedMemberLink::STATUS_CONFIRMED,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
            ]);

            AuditLogger::log('confirmed', $link,
                "Confirmed {$movedName} as a separated member. Record moved from household "
                . "{$familyHousehold->household_code} to {$presentHousehold->household_code}; "
                . "the two households are now linked.");
        });
    }

    /**
     * City Admin rules it out. Two people really can share a name.
     *
     * The row is kept rather than deleted so detection does not propose the
     * same pair again on the next page load, and so the decision is auditable.
     */
    public function reject(SeparatedMemberLink $link, User $actor, ?string $note = null): void
    {
        if (! $link->isPending()) {
            throw new \RuntimeException('This suggestion has already been decided.');
        }

        $link->update([
            'status' => SeparatedMemberLink::STATUS_REJECTED,
            'reviewed_by' => $actor->id,
            'reviewed_at' => now(),
            'note' => $note,
        ]);

        $who = $link->presentMember?->full_name ?? 'the flagged member';

        AuditLogger::log('rejected', $link,
            "Ruled that {$who} is not a separated member of household "
            . ($link->familyHousehold->household_code ?? 'unknown') . '.');
    }

    /**
     * Recompute a household's own figures by counting, never by arithmetic.
     *
     * number_of_members moves too: it is the family size printed beside the
     * present count, and leaving it stale would show "1 / 6" for a family of
     * five after somebody left the record.
     */
    private function recount(Household $household): void
    {
        $household->update([
            'number_of_members' => $household->members()->count(),
            'members_present' => $household->members()->where('is_present', true)->count(),
        ]);
    }
}
