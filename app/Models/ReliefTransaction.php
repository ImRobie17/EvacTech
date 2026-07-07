<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReliefTransaction extends Model
{
    protected $fillable = [
        'evacuation_center_id', 'relief_good_id', 'type', 'quantity', 'household_id',
        'source_or_recipient', 'recorded_by', 'transaction_date', 'remarks',
    ];

    protected function casts(): array
    {
        return ['transaction_date' => 'date'];
    }

    public function evacuationCenter(): BelongsTo
    {
        return $this->belongsTo(EvacuationCenter::class);
    }

    public function reliefGood(): BelongsTo
    {
        return $this->belongsTo(ReliefGood::class);
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
