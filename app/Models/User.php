<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;

class User extends Authenticatable
{
    use HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'role_id',
        'barangay_id',
        'name',
        'email',
        'contact_number',
        'password',
        'status',
        'last_login_at',
        // PHASE 7 ITEM 4. Fillable and cast, or Laravel drops them silently.
        'failed_login_attempts',
        'locked_until',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    /** Memoised so a single request never re-queries the roster. */
    private ?Collection $assignedCenterIdCache = null;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'locked_until' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    // -----------------------------------------------------------------
    // PHASE 7 ITEM 4 -- login lockout
    // -----------------------------------------------------------------

    /** Failures allowed before the account locks. The client asked for three. */
    public const MAX_LOGIN_ATTEMPTS = 3;

    /**
     * How long a lock lasts without administrator involvement.
     *
     * A lock that ONLY an administrator can clear is the obvious reading of
     * "3 failures locks", and it is the wrong one for this system. Barangay
     * personnel work during a disaster, at night, on a phone, often with the
     * City Admin unreachable -- and a hard lock at that moment takes a shelter's
     * only operator offline until somebody answers a call. Fifteen minutes still
     * makes online guessing hopeless (12 attempts an hour) while keeping the
     * failure mode survivable. An administrator can still clear it instantly.
     *
     * It is also the difference between a lockout being a defect and a disaster
     * during the defence itself.
     */
    public const LOCK_MINUTES = 15;

    /** Locks are evaluated ON READ, like transfer overdue. Nothing expires them. */
    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    /** Whole minutes remaining, floor 1 so a live lock never reads "0 minutes". */
    public function lockMinutesRemaining(): int
    {
        if (! $this->isLocked()) {
            return 0;
        }

        return max(1, (int) ceil(now()->diffInSeconds($this->locked_until, false) / 60));
    }

    /**
     * Record one failed sign-in. Returns true if this failure caused the lock.
     *
     * The counter is reset when the lock is applied rather than left at the cap,
     * so an account that comes out of a lock gets a fresh allowance instead of
     * re-locking on its very next mistake.
     */
    public function registerFailedLogin(): bool
    {
        $attempts = (int) $this->failed_login_attempts + 1;

        if ($attempts >= self::MAX_LOGIN_ATTEMPTS) {
            $this->forceFill([
                'failed_login_attempts' => 0,
                'locked_until' => now()->addMinutes(self::LOCK_MINUTES),
            ])->save();

            return true;
        }

        $this->forceFill(['failed_login_attempts' => $attempts])->save();

        return false;
    }

    /** Clear both counters. Called on a successful sign-in, on an administrator
     *  unlock, and whenever an administrator sets a new password. */
    public function clearLoginLock(): void
    {
        if ((int) $this->failed_login_attempts === 0 && $this->locked_until === null) {
            return; // nothing to write on the overwhelmingly common path
        }

        $this->forceFill([
            'failed_login_attempts' => 0,
            'locked_until' => null,
        ])->save();
    }

    public function passwordResetRequests(): HasMany
    {
        return $this->hasMany(PasswordResetRequest::class);
    }

    /**
     * Kept for city_admin / super_admin rows and historical reference only.
     *
     * PHASE 1 ITEM 1: barangay personnel are NO LONGER scoped by barangay. Do not
     * use this to decide what a staff member may see or do -- use
     * assignedCenters() / canAccessCenter() instead.
     */
    public function barangay(): BelongsTo
    {
        return $this->belongsTo(Barangay::class);
    }

    // -----------------------------------------------------------------
    // Shelter assignments
    // -----------------------------------------------------------------

    public function assignedCenters(): BelongsToMany
    {
        return $this->belongsToMany(EvacuationCenter::class, 'evacuation_center_user')
            ->withPivot(['assigned_by', 'assigned_at'])
            ->orderBy('evacuation_centers.name');
    }

    /** @return Collection<int, int> */
    public function assignedCenterIds(): Collection
    {
        return $this->assignedCenterIdCache ??= $this->assignedCenters()
            ->pluck('evacuation_centers.id')
            ->map(fn ($id) => (int) $id);
    }

    public function forgetAssignedCenterCache(): void
    {
        $this->assignedCenterIdCache = null;
    }

    public function hasShelterAssignment(): bool
    {
        return $this->assignedCenterIds()->isNotEmpty();
    }

    /**
     * City Admin and Super Admin reach every shelter. Barangay personnel reach
     * exactly the shelters on their roster -- no barangay fallback.
     */
    public function canAccessCenter(EvacuationCenter|int|null $center): bool
    {
        if ($center === null) {
            return false;
        }
        if (! $this->isBarangayPersonnel()) {
            return true;
        }

        $id = $center instanceof EvacuationCenter ? $center->id : (int) $center;

        return $this->assignedCenterIds()->contains($id);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role?->name === Role::SUPER_ADMIN;
    }

    public function isCityAdmin(): bool
    {
        return $this->role?->name === Role::CITY_ADMIN;
    }

    public function isBarangayPersonnel(): bool
    {
        return $this->role?->name === Role::BARANGAY_PERSONNEL;
    }
}
