<?php

namespace App\Services;

use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\VulnerableClassification;
use App\Support\AgeTier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The ONE implementation of "write this family's members and tags".
 *
 * Before Phase 2 this logic existed four times, copy-pasted into
 * Barangay\EvacueeProfilingController, CityAdmin\EvacueeProfilingController,
 * CityAdmin\ShelterDetailController and (partially) the shelter check-in path.
 * That is precisely why the tags[] bug survived an entire phase: fixing it in
 * one place fixed one screen out of four. Every caller now routes through here.
 */
class HouseholdMemberSync
{
    /** Cached per request -- this runs once per member row otherwise. */
    private ?Collection $selectableIds = null;

    /** Same, for the Phase 7 item 2 sex check. */
    private ?Collection $femaleOnlyIds = null;

    /**
     * @param  array  $members        validated members payload
     * @param  bool   $checkin        mark submitted members present
     * @param  bool   $keepPresence   leave is_present alone (edit, not check-in)
     * @param  bool   $pruneMissing   delete members absent from the payload
     */
    public function sync(
        Household $household,
        array $members,
        bool $checkin = false,
        bool $keepPresence = false,
        bool $pruneMissing = true
    ): void {
        $headSet = false;
        $keptIds = [];
        $anyHeadFlagged = collect($members)->contains(fn ($x) => ! empty($x['is_head']));

        // Phase 3 item 9. Resolved ONCE, before the loop, because a member row
        // can be written before the head row is reached when is_head is flagged
        // on something other than index 0.
        $headLastName = $this->headLastName($members, $anyHeadFlagged);

        foreach ($members as $i => $m) {
            $isHead = ! $headSet && ! empty($m['is_head']);
            if ($i === 0 && ! $anyHeadFlagged) {
                $isHead = true; // default: first row is the head if none flagged
            }
            if ($isHead) {
                $headSet = true;
            }

            $member = $this->writeMember($household, $m, $isHead, $checkin, $keepPresence, $headLastName);
            $keptIds[] = $member->id;

            $this->applyTags($member, $m['tags'] ?? []);

            if ($isHead) {
                $household->update(['head_member_id' => $member->id]);
            }
        }

        if ($pruneMissing) {
            $household->members()->whereNotIn('id', $keptIds)->delete();
        }
    }

    /**
     * Phase 3 item 9 -- the surname a blank member row inherits.
     *
     * Head detection MIRRORS sync() exactly: the first row flagged is_head, or
     * row 0 when nothing is flagged. Written as its own method so the two can
     * never drift into disagreeing about which row is the head.
     *
     * Returns '' when the head's own surname is blank. Validation requires it,
     * so that should not happen -- but inheriting an empty string would build a
     * full_name beginning ", ", and a family whose every member displayed as
     * ", Juan" would be worse than the validation error the operator is about
     * to see anyway.
     */
    private function headLastName(array $members, bool $anyHeadFlagged): string
    {
        $head = null;

        if ($anyHeadFlagged) {
            foreach ($members as $m) {
                if (! empty($m['is_head'])) {
                    $head = $m;
                    break;
                }
            }
        } else {
            $head = $members[array_key_first($members)] ?? null;
        }

        return trim($head['last_name'] ?? '');
    }

    private function writeMember(
        Household $household,
        array $m,
        bool $isHead,
        bool $checkin,
        bool $keepPresence,
        string $headLastName = ''
    ): HouseholdMember {
        // Phase 3 item 9. A blank surname inherits the head's, which is the
        // overwhelmingly common case and saves retyping it for every child in a
        // surge. Applied at WRITE TIME ONLY: once a member is saved, that name
        // is theirs, and editing the head's surname later never rewrites it.
        // Mixed-surname families stay correct because the operator can type
        // over the value the form pre-fills.
        $lastName = trim($m['last_name'] ?? '');
        if ($lastName === '' && ! $isHead) {
            $lastName = $headLastName;
        }

        $fullName = trim($lastName . ', ' . $m['first_name'] . ' ' . ($m['middle_name'] ?? ''));

        // Birthdate is OPTIONAL as of Phase 2: staff can tag a family fast during
        // a surge and fill birthdays in later. When it is absent the manually
        // chosen age group is stored as a fallback; when it is present the
        // fallback is NULLED, so the two can never drift apart in the database.
        $birthdate = ! empty($m['birthdate']) ? Carbon::parse($m['birthdate']) : null;
        $fallback = null;

        if (! $birthdate) {
            $submitted = $m['age_group'] ?? null;
            $fallback = AgeTier::isValid($submitted) ? $submitted : null;
        }

        $attrs = [
            'full_name' => $fullName,
            'birthdate' => $birthdate,
            'age_tier_fallback' => $fallback,
            'sex' => $m['sex'],
            'is_household_head' => $isHead,
            'family_role' => $isHead ? 'head' : 'member',
        ];

        // The legacy 'age' column is kept as a DISPLAY SNAPSHOT ONLY, because
        // both ReportControllers still print $m->age. It is never used to derive
        // a tier -- whole years cannot tell an Infant from a Toddler. Phase 3
        // should switch those reports to $m->displayAge() and this can go.
        $attrs['age'] = $birthdate ? intdiv(AgeTier::monthsSince($birthdate), 12) : null;

        if ($checkin) {
            $attrs['is_present'] = array_key_exists('is_present', $m)
                ? ! empty($m['is_present'])
                : true;
        } elseif (! $keepPresence) {
            $attrs['is_present'] = false;
        }

        if (! empty($m['id'])) {
            $member = $household->members()->whereKey($m['id'])->first();
            if ($member) {
                $member->update($attrs);

                return $member;
            }
        }

        return $household->members()->create($attrs);
    }

    /**
     * Apply the ticked categories.
     *
     * NOT sync(). sync() detaches everything absent from the list, which would
     * silently destroy the retired Senior Citizen / Infant rows that Phase 2
     * deliberately preserved as history the first time anyone edited a family.
     * Instead: detach only the SELECTABLE tags that were unticked, and attach
     * the ones that were ticked. Retired rows are never touched by an edit.
     *
     * The auto-tagging of Senior (60+) and Infant (0-5) that used to live here
     * is GONE. Those are age tiers now, derived on read.
     */
    /*
     * SEPARATED HOUSEHOLDS DROP 2 made this public. It was private, and
     * reunification needs to carry a vulnerability tag from a record being
     * deleted onto the record being kept.
     *
     * Published rather than worked around deliberately. This is the ONE write
     * path for member tags -- it is where female-only categories are stripped
     * from any non-female member, and where rows created before that rule
     * existed self-heal. Writing to member_vulnerabilities directly from the
     * new service would have created a second path and, in time, a
     * disagreement. Nothing about the method's behaviour changes.
     */
    public function applyTags(HouseholdMember $member, array $submitted): void
    {
        $selectable = $this->selectableIds();

        $wanted = collect($submitted)
            ->map(fn ($t) => (int) $t)
            ->unique()
            ->intersect($selectable)
            ->values();

        // PHASE 7 ITEM 2 -- the backstop. MemberRules::tags() rejects this at
        // the controller and the form hides the checkboxes, but this method is
        // the ONE write path shared by all four callers, and a future caller
        // that skips those rules must not be able to put Pregnant Woman on a
        // male evacuee. The member's stored sex is authoritative: writeMember()
        // has already run, so $member->sex is the value just submitted.
        //
        // Dropping the ids here rather than ignoring them also self-heals: they
        // fall into $unwanted below and are detached, so any row created before
        // this rule existed is cleaned the next time the family is saved.
        if ($member->sex !== 'female') {
            $wanted = $wanted->diff($this->femaleOnlyIds())->values();
        }

        $unwanted = $selectable->diff($wanted)->values();

        if ($unwanted->isNotEmpty()) {
            $member->vulnerableClassifications()->detach($unwanted->all());
        }

        if ($wanted->isNotEmpty()) {
            $member->vulnerableClassifications()->syncWithoutDetaching(
                $wanted->mapWithKeys(fn ($id) => [
                    $id => ['tagged_by' => auth()->id(), 'tagged_at' => now()],
                ])->all()
            );
        }
    }

    private function selectableIds(): Collection
    {
        return $this->selectableIds ??= VulnerableClassification::selectable()->pluck('id');
    }

    private function femaleOnlyIds(): Collection
    {
        return $this->femaleOnlyIds ??= VulnerableClassification::femaleOnly()->pluck('id');
    }
}
