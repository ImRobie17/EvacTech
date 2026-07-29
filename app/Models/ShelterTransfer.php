<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PHASE 2 ITEM 8 -- a single shelter-to-shelter move.
 *
 * State machine:
 *
 *   pending --(destination confirms)--> approved
 *                                          |
 *                              (origin records OUT)
 *                                          v
 *                                      in_transit
 *                                          |
 *                            (destination records IN)
 *                                          v
 *                                       completed
 *
 *   pending  --(destination refuses)--> refused    [alerts City Admin]
 *   pending  --(origin or City cancels)--> cancelled
 *   approved --(origin or City cancels)--> cancelled
 *   in_transit --(CITY ADMIN ONLY cancels)--> cancelled
 *
 * There is no refuse-after-confirm path on purpose. A destination that has
 * confirmed is committed; if it changes its mind after the family arrives it
 * receives them and files a fresh transfer back out, so both movements appear
 * in the audit trail.
 *
 * OCCUPANCY: the household stays checked_in AT THE ORIGIN with members_present
 * intact for the whole of pending/approved/in_transit. Nothing here touches
 * households.status, and the enum value 'transferred' is deliberately never
 * used -- setting it would drop the family out of recalcOccupancy() at the
 * origin while they are not yet counted anywhere else, which is exactly the
 * vanishing-headcount bug this design avoids. Transit state lives on this row
 * and nowhere else.
 */
class ShelterTransfer extends Model
{
    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const IN_TRANSIT = 'in_transit';
    public const COMPLETED = 'completed';
    public const REFUSED = 'refused';
    public const CANCELLED = 'cancelled';

    /** Statuses where the family is committed to a move that has not finished. */
    public const OPEN_STATUSES = [self::PENDING, self::APPROVED, self::IN_TRANSIT];

    /**
     * Minutes in transit before a transfer is flagged overdue. Computed on read,
     * never by a scheduler: there is no cron in this project, and a UI that
     * depended on one would silently show nothing on a machine where Windows
     * Task Scheduler was never configured.
     *
     * The env override exists so the overdue state can be demonstrated at the
     * defence without waiting an hour.
     */
    public const OVERDUE_MINUTES = 60;

    protected $fillable = [
        'household_id',
        'from_center_id',
        'to_center_id',
        'status',
        'reason',
        'origin_checked_in_at',
        'members_expected',
        'members_received',
        'requested_by',
        'requested_at',
        'confirmed_by',
        'confirmed_at',
        'departed_by',
        'departed_at',
        'received_by',
        'received_at',
        'refused_by',
        'refused_at',
        'refusal_reason',
        'cancelled_by',
        'cancelled_at',
        'cancellation_reason',
    ];

    // Gotcha 2: every timestamp needs a cast or it comes back as a plain string
    // and the ->format() calls in the Blade tables fatal.
    protected function casts(): array
    {
        return [
            'origin_checked_in_at' => 'datetime',
            'requested_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'departed_at' => 'datetime',
            'received_at' => 'datetime',
            'refused_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    // -----------------------------------------------------------------
    // Relations
    // -----------------------------------------------------------------

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function fromCenter(): BelongsTo
    {
        return $this->belongsTo(EvacuationCenter::class, 'from_center_id');
    }

    public function toCenter(): BelongsTo
    {
        return $this->belongsTo(EvacuationCenter::class, 'to_center_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function departedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'departed_by');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function refusedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'refused_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    // -----------------------------------------------------------------
    // State
    // -----------------------------------------------------------------

    public static function overdueMinutes(): int
    {
        $configured = (int) env('EVACTECH_TRANSFER_OVERDUE_MINUTES', self::OVERDUE_MINUTES);

        return $configured > 0 ? $configured : self::OVERDUE_MINUTES;
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    /** In transit for longer than the threshold: nobody has clicked Receive. */
    public function isOverdue(): bool
    {
        return $this->status === self::IN_TRANSIT
            && $this->departed_at !== null
            && $this->departed_at->lte(now()->subMinutes(self::overdueMinutes()));
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::PENDING => 'Awaiting confirmation',
            self::APPROVED => 'Approved, not yet departed',
            self::IN_TRANSIT => 'In transit',
            self::COMPLETED => 'Completed',
            self::REFUSED => 'Refused',
            self::CANCELLED => 'Cancelled',
            default => ucfirst(str_replace('_', ' ', (string) $this->status)),
        };
    }

    /**
     * Status is never colour-only (accessibility rule in the design system):
     * every badge that uses this class also prints statusLabel() as text.
     */
    public function badgeClass(): string
    {
        if ($this->isOverdue()) {
            return 'badge-danger';
        }

        return match ($this->status) {
            self::PENDING => 'badge-warning',
            self::APPROVED => 'badge-info',
            self::IN_TRANSIT => 'badge-info',
            self::COMPLETED => 'badge-success',
            self::REFUSED => 'badge-danger',
            self::CANCELLED => 'badge-warning',
            default => 'badge-info',
        };
    }

    /** Minutes elapsed since departure, for the "in transit for N" column. */
    public function minutesInTransit(): ?int
    {
        return $this->departed_at ? $this->departed_at->diffInMinutes(now()) : null;
    }

    // -----------------------------------------------------------------
    // Scopes
    // -----------------------------------------------------------------

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->where('status', self::IN_TRANSIT)
            ->whereNotNull('departed_at')
            ->where('departed_at', '<=', now()->subMinutes(self::overdueMinutes()));
    }

    /**
     * Transfers a user may see. Barangay personnel see anything touching a
     * shelter on their roster, at either end -- an outbound family is their
     * business until it is received, and an inbound one is their business from
     * the moment it is proposed.
     *
     * Scoped through evacuation_center_user via assignedCenterIds(), never by
     * comparing barangay ids. Comparing barangay ids is what produced the old
     * City Admin check-out 403.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if (! $user->isBarangayPersonnel()) {
            return $query; // City Admin and Super Admin see every transfer.
        }

        $ids = $user->assignedCenterIds()->all();

        return $query->where(fn ($q) => $q
            ->whereIn('from_center_id', $ids)
            ->orWhereIn('to_center_id', $ids));
    }

    // -----------------------------------------------------------------
    // Permissions
    //
    // canAccessCenter() returns true for every non-barangay role, so each of
    // these reads as "the destination (or origin) side, or City Admin".
    // -----------------------------------------------------------------

    /** The destination decides whether to accept, so only it may confirm. */
    public function canBeConfirmedBy(User $user): bool
    {
        return $this->status === self::PENDING && $user->canAccessCenter($this->to_center_id);
    }

    /** Refusal is possible only BEFORE the family travels. */
    public function canBeRefusedBy(User $user): bool
    {
        return $this->status === self::PENDING && $user->canAccessCenter($this->to_center_id);
    }

    /** The origin records the OUT time, because that is where the family leaves. */
    public function canBeDepartedBy(User $user): bool
    {
        return $this->status === self::APPROVED && $user->canAccessCenter($this->from_center_id);
    }

    public function canBeReceivedBy(User $user): bool
    {
        return $this->status === self::IN_TRANSIT && $user->canAccessCenter($this->to_center_id);
    }

    /**
     * Before departure either side of the origin may call it off. Once the
     * family is on the road it is CITY ADMIN ONLY.
     *
     * That last rule is what stops a family who never arrived from sitting in
     * transit forever: the destination cannot refuse post-confirmation and the
     * origin should not be able to close a record for people who are physically
     * in motion, so the city closes it. Because the household never moved in the
     * database, cancelling an in-transit transfer leaves it checked in at the
     * origin with members_present untouched -- there is nothing to recalculate
     * and nothing to reconcile.
     */
    public function canBeCancelledBy(User $user): bool
    {
        if ($this->status === self::IN_TRANSIT) {
            return $user->isCityAdmin() || $user->isSuperAdmin();
        }

        if (! in_array($this->status, [self::PENDING, self::APPROVED], true)) {
            return false;
        }

        return $user->isCityAdmin() || $user->canAccessCenter($this->from_center_id);
    }

    /** Does this row want something from this user right now? Drives the glow. */
    public function needsActionFrom(User $user): bool
    {
        return $this->canBeConfirmedBy($user)
            || $this->canBeDepartedBy($user)
            || $this->canBeReceivedBy($user);
    }
}
