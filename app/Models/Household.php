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

    public function reliefTransactions(): HasMany
    {
        return $this->hasMany(ReliefTransaction::class);
    }

    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }
}
