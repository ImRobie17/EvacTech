<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReliefAllocationRequest extends Model
{
    protected $fillable = [
        'evacuation_center_id', 'relief_good_id', 'requested_quantity', 'status',
        'requested_by', 'approved_by', 'requested_at', 'resolved_at',
        'request_group_id', 'custom_good_name', 'custom_description', 'received_quantity',
        'donor_type', 'donor_name', 'monetary_value',
        'delivery_closed_at', 'delivery_closed_by', 'delivery_closure_reason',
    ];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'resolved_at' => 'datetime',
            'delivery_closed_at' => 'datetime',
            'monetary_value' => 'decimal:2',
        ];
    }

    public function getRemainingQuantityAttribute(): int
    {
        return max(0, (int) $this->requested_quantity - (int) $this->received_quantity);
    }

    public function getDeliveryStatusAttribute(): string
    {
        if (! in_array($this->status, ['approved', 'fulfilled'], true)) {
            return $this->status;
        }
        if ($this->delivery_closed_at !== null) {
            return 'closed short';
        }
        if ($this->remaining_quantity === 0) {
            return 'received';
        }
        return (int) $this->received_quantity > 0 ? 'partially received' : 'awaiting arrival';
    }

    public function evacuationCenter(): BelongsTo
    {
        return $this->belongsTo(EvacuationCenter::class);
    }

    public function reliefGood(): BelongsTo
    {
        return $this->belongsTo(ReliefGood::class);
    }

    public function receipts(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ReliefTransaction::class, 'relief_allocation_request_id');
    }

    public function latestReceipt(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(ReliefTransaction::class, 'relief_allocation_request_id')->latestOfMany();
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
