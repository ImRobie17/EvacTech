<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Household extends Model
{
    // NOTE: members_present / checked_in_at / checked_out_at were added by the
    // Barangay Personnel migration. They MUST be listed here -- Laravel silently
    // discards non-fillable fields on update()/create(), which is why the
    // headcount stayed at 0 while current_occupancy (set via increment(), which
    // bypasses mass-assignment protection) updated correctly.
    protected $fillable = [
        'household_code', 'origin_barangay_id', 'evacuation_center_id', 'head_member_id',
        // PHASE 9 ITEM 2. Listed here for the same reason the note above gives:
        // a column absent from $fillable is silently discarded by update(), and
        // an acting head that never saved would look exactly like a UI bug.
        'acting_head_member_id',
        // PHASE 10A. Same rule again. Set ONLY by SeparationService::confirm();
        // a link that never saved would look exactly like a confirm button that
        // does nothing, and the counting changes below would silently no-op.
        'separated_from_household_id',
        'origin_address', 'number_of_members', 'members_present', 'status',
        'checked_in_at', 'checked_out_at', 'registered_by',
    ];

    // Without these casts the timestamps come back as plain strings and any
    // ->format() / ->diffForHumans() call in a view would fatal.
    protected function casts(): array
    {
        return [
            'checked_in_at' => 'datetime',
            'checked_out_at' => 'datetime',
        ];
    }

    /**
     * Phase 2 item 6 -- "Single Headed Household".
     *
     * DERIVED, never stored, and deliberately NOT a vulnerable classification.
     * member_vulnerabilities is a per-MEMBER pivot; putting a household-level
     * fact there would force a choice about which member carries it and would
     * corrupt every count grouped by classification.
     *
     * Definition confirmed with Cabuyao's shelter operations manager: a
     * one-person family who is currently checked in at the shelter. Because it
     * is tied to check-in state, the flag correctly does not exist before
     * check-in, and clears itself if the family checks out or the rest of them
     * arrive.
     *
     * PHASE 10A adds the separated-fragment exclusion. A confirmed separated
     * individual is a checked-in household of one and would otherwise satisfy
     * the rule exactly -- but she is a FRAGMENT of a larger family, not a
     * one-person household, and Single Headed Household is a welfare category
     * that travels onto a signed CSWDO form. Counting her there is a false
     * figure about a real family's circumstances.
     */
    public function isSingleHeaded(): bool
    {
        return $this->status === 'checked_in'
            && (int) $this->members_present === 1
            && $this->separated_from_household_id === null;
    }

    /** Query-side twin of isSingleHeaded(), for counts and report cross-tabs. */
    public function scopeSingleHeaded($query)
    {
        return $query->where('status', 'checked_in')
            ->where('members_present', 1)
            ->whereNull('separated_from_household_id');
    }

    /**
     * PHASE 10A -- true when this household exists only because one of its
     * occupants was separated from a family sheltering elsewhere.
     *
     * The one clause behind both counting rules, named so call sites read as
     * intent rather than as a null check on a foreign key.
     */
    public function isSeparatedFragment(): bool
    {
        return $this->separated_from_household_id !== null;
    }

    /** The family this household is a fragment of, once City Admin has confirmed. */
    public function separatedFrom(): BelongsTo
    {
        return $this->belongsTo(Household::class, 'separated_from_household_id');
    }

    /** Fragments confirmed as belonging to THIS family, at whatever shelter. */
    public function separatedFragments(): HasMany
    {
        return $this->hasMany(Household::class, 'separated_from_household_id');
    }

    public function originBarangay(): BelongsTo
    {
        return $this->belongsTo(Barangay::class, 'origin_barangay_id');
    }

    public function evacuationCenter(): BelongsTo
    {
        return $this->belongsTo(EvacuationCenter::class);
    }

    public function headMember(): BelongsTo
    {
        return $this->belongsTo(HouseholdMember::class, 'head_member_id');
    }

    /**
     * PHASE 9 ITEM 2 -- the stand-in, when the substantive head is not present.
     *
     * DELIBERATELY NOT a replacement for headMember(). Every existing caller of
     * headMember() -- reports, the CSWDO IDP Monitoring Form, transfers, relief,
     * every search and both ORDER BY subqueries -- keeps reading the substantive
     * head, because that is the family's actual head and it is what a City
     * Social Welfare officer signs for. This relation answers a different and
     * purely operational question: who is answerable for this family at the
     * shelter right now.
     */
    public function actingHeadMember(): BelongsTo
    {
        return $this->belongsTo(HouseholdMember::class, 'acting_head_member_id');
    }

    /**
     * Whoever is answerable for the family at the shelter: the acting head when
     * one has been designated, otherwise the substantive head.
     *
     * Use this ONLY on shelter-operations screens. Reporting reads headMember().
     */
    public function responsibleHead(): ?HouseholdMember
    {
        return $this->actingHeadMember ?? $this->headMember;
    }

    /**
     * PHASE 9 ITEMS 3 + 5 -- which control a check-in picker row actually needs.
     *
     * Both check-in pickers ask this, and it lives here rather than in either
     * controller for the reason gotcha 19 records: the check-in guard was
     * written twice and the two copies drifted, which is the bug Phase 9 item 3
     * exists to close. One derivation, both callers.
     *
     * 'checkin'  -- not checked in anywhere. The ordinary path.
     * 'arrival'  -- already checked in AT THIS SHELTER with somebody still
     *               absent. Check-in cannot help; presence correction can, and
     *               it is the only path that can set is_present back to true.
     * 'transfer' -- checked in at a DIFFERENT shelter. Moving them is a
     *               transfer, which keeps a lifecycle and a record; a second
     *               check-in would move them with neither.
     *
     * Requires members_present and the absent count to be meaningful, so callers
     * should have loaded them. A household checked in here with nobody absent
     * returns 'arrival' too, but those rows are excluded from the query -- there
     * is nothing for staff to do with them.
     */
    public function checkinAction(?int $targetCenterId): string
    {
        if ($this->status !== 'checked_in') {
            return 'checkin';
        }

        return (int) $this->evacuation_center_id === (int) $targetCenterId
            ? 'arrival'
            : 'transfer';
    }

    /**
     * PHASE 9 ITEM 1 -- which member the search term actually matched, when it
     * was not the head.
     *
     * Answers the question a widened search creates: item 1 lets a search for
     * "Maria" return a row headed "Dela Cruz, Juan", and without this the
     * operator has no way to tell why that family appeared.
     *
     * Returns null when there is nothing worth saying -- no term, no member
     * matched (the term hit the household CODE, which is how the two transfer
     * searches can also match), or the match WAS the head and the row already
     * shows that name.
     *
     * Expects `members` to be loaded. Every caller eager-loads it and only when a
     * term is present, so a blank-term prefill costs nothing extra.
     *
     * Case-insensitive via stripos, to agree with the SQL LIKE in
     * HouseholdMember::scopeNameMatches(): MySQL's default collation is
     * case-insensitive, and a label that disagreed with the query that produced
     * it would be worse than no label at all.
     */
    public function matchedMemberName(?string $term): ?string
    {
        $term = trim((string) $term);
        if ($term === '') {
            return null;
        }

        $match = $this->members->first(
            fn ($m) => stripos((string) $m->full_name, $term) !== false
        );

        if (! $match) {
            return null;
        }

        return (int) $match->id === (int) $this->head_member_id ? null : $match->full_name;
    }

    /** True when a stand-in is currently designated. */
    public function hasActingHead(): bool
    {
        return $this->acting_head_member_id !== null;
    }

    /**
     * True when the substantive head is recorded as physically present.
     *
     * Drives the revert prompt on the Update Presence screen: an acting head
     * only needs a decision once the real head has actually turned up.
     */
    public function substantiveHeadIsPresent(): bool
    {
        if (! $this->head_member_id) {
            return false;
        }

        return (bool) $this->members()
            ->whereKey($this->head_member_id)
            ->where('is_present', true)
            ->exists();
    }

    public function members(): HasMany
    {
        return $this->hasMany(HouseholdMember::class);
    }

    public function attendanceLogs(): HasMany
    {
        return $this->hasMany(AttendanceLog::class);
    }

    public function transfers(): HasMany
    {
        return $this->hasMany(HouseholdTransfer::class);
    }

    /**
     * PHASE 2 ITEM 8 -- shelter-to-shelter moves.
     *
     * Separate from transfers() above, which is family-HEAD-role changes and
     * always has from_center_id == to_center_id. The two answer different
     * questions and share no lifecycle.
     */
    public function shelterTransfers(): HasMany
    {
        return $this->hasMany(ShelterTransfer::class);
    }

    /**
     * The transfer currently in flight for this family, if any.
     *
     * Guard used before starting a second transfer and before allowing a
     * check-out: a family who is committed to a move must not be checked out
     * from underneath it, or the transfer would try to receive a household that
     * is no longer anywhere.
     */
    public function openTransfer(): ?ShelterTransfer
    {
        return $this->shelterTransfers()
            ->whereIn('status', ShelterTransfer::OPEN_STATUSES)
            ->latest('id')
            ->first();
    }

    public function reliefTransactions(): HasMany
    {
        return $this->hasMany(ReliefTransaction::class);
    }

    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }
}
