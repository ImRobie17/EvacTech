<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
            'password' => 'hashed',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
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
