<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EvacuationCenter extends Model
{
    protected $fillable = [
        'barangay_id', 'name', 'address', 'latitude', 'longitude',
        'capacity', 'current_occupancy', 'has_water_supply', 'has_medical_desk',
        'has_power', 'has_communal_kitchen', 'status', 'managed_by', 'created_by',
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

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'managed_by');
    }

    public function households(): HasMany
    {
        return $this->hasMany(Household::class);
    }

    public function inventories(): HasMany
    {
        return $this->hasMany(ReliefInventory::class);
    }

    public function reliefTransactions(): HasMany
    {
        return $this->hasMany(ReliefTransaction::class);
    }

    public function occupancyPercent(): float
    {
        return $this->capacity > 0 ? round(($this->current_occupancy / $this->capacity) * 100, 1) : 0.0;
    }
}
