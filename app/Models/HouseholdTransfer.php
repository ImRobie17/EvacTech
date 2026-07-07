<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HouseholdTransfer extends Model
{
    protected $fillable = [
        'household_id', 'from_center_id', 'to_center_id', 'new_head_member_id',
        'reason', 'transferred_by', 'transferred_at',
    ];

    protected function casts(): array
    {
        return ['transferred_at' => 'datetime'];
    }

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

    public function newHeadMember(): BelongsTo
    {
        return $this->belongsTo(HouseholdMember::class, 'new_head_member_id');
    }

    public function transferredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'transferred_by');
    }
}
