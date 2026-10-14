<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SpecialReliefRequest extends Model
{
    protected $fillable = [
        'household_id', 'evacuation_center_id', 'item_description', 'quantity',
        'status', 'requested_by', 'reviewed_by', 'reviewed_at', 'remarks',
        'received_quantity', 'distributed_quantity',
    ];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    public function getRemainingToReceiveAttribute(): int
    {
        return max(0, (int) $this->quantity - (int) $this->received_quantity);
    }

    public function getAvailableToDistributeAttribute(): int
    {
        return max(0, (int) $this->received_quantity - (int) $this->distributed_quantity);
    }

    public function getFulfillmentStatusAttribute(): string
    {
        if ($this->status !== 'approved') {
            return $this->status;
        }
        if ($this->available_to_distribute > 0) {
            return 'ready to distribute';
        }
        if ((int) $this->received_quantity > 0) {
            return 'partially fulfilled';
        }
        return 'awaiting arrival';
    }

    public function movements(): HasMany
    {
        return $this->hasMany(SpecialReliefMovement::class, 'special_relief_request_id');
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function evacuationCenter(): BelongsTo
    {
        return $this->belongsTo(EvacuationCenter::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
