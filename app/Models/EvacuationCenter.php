<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class EvacuationCenter extends Model
{
    // NOTE: `managed_by` was removed in 2025_06_01_000002. Shelter staffing lives
    // in the evacuation_center_user pivot -- see assignedStaff().
    protected $fillable = [
        'barangay_id', 'name', 'address', 'latitude', 'longitude',
        'capacity', 'current_occupancy', 'has_water_supply', 'has_medical_desk',
        'has_power', 'has_communal_kitchen', 'status', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'has_water_supply' => 'boolean',
            'has_medical_desk' => 'boolean',
            'has_power' => 'boolean',
            'has_communal_kitchen' => 'boolean',
        ];
    }

    public function barangay(): BelongsTo
    {
        return $this->belongsTo(Barangay::class);
    }

    /**
     * Staff cleared to operate this shelter. Flat roster -- every member has
     * identical rights, there is no lead and no shift gating.
     */
    public function assignedStaff(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'evacuation_center_user')
            ->withPivot(['assigned_by', 'assigned_at'])
            ->orderBy('users.name');
    }

    public function households(): HasMany
    {
        return $this->hasMany(Household::class);
    }

    /**
     * PHASE 3 ITEM 11b -- every member registered at this shelter.
     *
     * Exists so a filtered report can count MATCHING MEMBERS per shelter in one
     * query. withCount('households') counts FAMILIES, which is the wrong unit
     * for "how many people here matched your filter", and counting per row in
     * PHP would be a query per shelter.
     */
    public function householdMembers(): HasManyThrough
    {
        return $this->hasManyThrough(HouseholdMember::class, Household::class);
    }

    public function inventories(): HasMany
    {
        return $this->hasMany(ReliefInventory::class);
    }

    public function reliefTransactions(): HasMany
    {
        return $this->hasMany(ReliefTransaction::class);
    }

    // -----------------------------------------------------------------
    // Occupancy
    // -----------------------------------------------------------------

    /**
     * Recompute current_occupancy from the households actually checked in here.
     *
     * This REPLACES the old increment()/decrement() calls scattered through the
     * controllers. Those drifted out of sync whenever a request failed halfway,
     * and would have drifted much worse once households can move between
     * shelters. Derive, never accumulate.
     *
     * Deliberately does NOT touch `status`: an overcapacity shelter stays
     * active so it can keep logging and tracking evacuees.
     */
    public function recalcOccupancy(): int
    {
        $total = (int) $this->households()
            ->where('status', 'checked_in')
            ->sum('members_present');

        if ($this->current_occupancy !== $total) {
            $this->forceFill(['current_occupancy' => $total])->save();
        }

        return $total;
    }

    public function occupancyPercent(): float
    {
        return $this->capacity > 0 ? round(($this->current_occupancy / $this->capacity) * 100, 1) : 0.0;
    }

    public function isOvercapacity(): bool
    {
        return $this->capacity > 0 && $this->current_occupancy > $this->capacity;
    }

    /** Headcount above capacity, 0 when within capacity. */
    public function overBy(): int
    {
        return $this->isOvercapacity() ? $this->current_occupancy - $this->capacity : 0;
    }

    /**
     * Capacity band per the design system thresholds:
     * <70% ok | 70-89% warn | 90-100% full | >100% over.
     * Independent of `status`, which only says active/inactive.
     */
    public function capacityBand(): string
    {
        if ($this->capacity <= 0) {
            return 'unknown';
        }
        $pct = ($this->current_occupancy / $this->capacity) * 100;

        return match (true) {
            $pct > 100 => 'over',
            $pct >= 90 => 'full',
            $pct >= 70 => 'warn',
            default => 'ok',
        };
    }

    /**
     * What the operator should read on a badge. Overcapacity is surfaced here
     * rather than in `status` so the shelter is never auto-deactivated.
     */
    public function statusLabel(): string
    {
        if ($this->status === 'active' && $this->isOvercapacity()) {
            return 'Overcapacity';
        }

        return ucfirst($this->status);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
