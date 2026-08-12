<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PHASE 10A -- a suspected separated family member, awaiting a decision.
 *
 * Barangay staff FLAG; City Admin CONFIRMS. Two-step on purpose: confirmation
 * moves a person's record between two households at two different shelters and
 * changes both occupancy figures, which is more authority than front-line staff
 * should hold over a family they can only see one end of.
 *
 * Statuses are terminal apart from pending. There is no "unconfirm": undoing a
 * confirmed link means moving the person back, which is a reunification, and
 * reunification is Phase 10B through the transfer module.
 */
class SeparatedMemberLink extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_REJECTED = 'rejected';

    // Every column that any write path sets. A column absent from $fillable is
    // silently discarded by create()/update(), which is the bug that kept the
    // headcount at 0 for a phase -- see the note on Household.
    protected $fillable = [
        'family_member_id', 'family_household_id',
        'present_member_id', 'present_household_id',
        'status', 'flagged_by', 'reviewed_by', 'reviewed_at', 'note',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
        ];
    }

    // ----------------------------------------------------------------------
    // Relations
    // ----------------------------------------------------------------------

    /** The original row inside the family. This is the row that MOVES. */
    public function familyMember(): BelongsTo
    {
        return $this->belongsTo(HouseholdMember::class, 'family_member_id');
    }

    /** The row at the shelter where the person physically is. DELETED on confirm. */
    public function presentMember(): BelongsTo
    {
        return $this->belongsTo(HouseholdMember::class, 'present_member_id');
    }

    public function familyHousehold(): BelongsTo
    {
        return $this->belongsTo(Household::class, 'family_household_id');
    }

    public function presentHousehold(): BelongsTo
    {
        return $this->belongsTo(Household::class, 'present_household_id');
    }

    /** Soft-deleted users return null here. Guard before use -- gotcha 13. */
    public function flagger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'flagged_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    // ----------------------------------------------------------------------
    // Scopes
    // ----------------------------------------------------------------------

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeDecided(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_CONFIRMED, self::STATUS_REJECTED]);
    }

    /**
     * Everything already flagged, whatever the outcome, as a pair set.
     *
     * Detection subtracts this so a suggestion that has been ruled on does not
     * reappear on the barangay screen the next time the page loads. Returned as
     * "familyId-presentId" strings because the caller compares candidate pairs,
     * not rows.
     *
     * @return array<int, string>
     */
    public static function knownPairs(): array
    {
        return self::query()
            ->get(['family_member_id', 'present_member_id'])
            ->map(fn ($l) => $l->family_member_id . '-' . $l->present_member_id)
            ->all();
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_CONFIRMED => 'Confirmed',
            self::STATUS_REJECTED => 'Not the same person',
            default => 'Awaiting City Admin',
        };
    }
}
