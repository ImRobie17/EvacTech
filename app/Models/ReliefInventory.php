<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReliefInventory extends Model
{
    protected $fillable = [
        'evacuation_center_id', 'relief_good_id', 'quantity_on_hand', 'reorder_level', 'last_updated_at',
    ];

    protected function casts(): array
    {
        return ['last_updated_at' => 'datetime'];
    }

    public function evacuationCenter(): BelongsTo
    {
        return $this->belongsTo(EvacuationCenter::class);
    }

    public function reliefGood(): BelongsTo
    {
        return $this->belongsTo(ReliefGood::class);
    }

    public function isLowStock(): bool
    {
        return $this->quantity_on_hand <= $this->reorder_level;
    }
}
