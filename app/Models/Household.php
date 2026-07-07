<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Household extends Model
{
    protected $fillable = [
        'household_code', 'origin_barangay_id', 'evacuation_center_id', 'head_member_id',
        'origin_address', 'number_of_members', 'status', 'registered_by',
    ];

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
