<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpecialReliefMovement extends Model
{
    protected $fillable = [
        'special_relief_request_id', 'action', 'quantity', 'recorded_by',
        'submission_token', 'remarks',
    ];

    public function specialRequest(): BelongsTo
    {
        return $this->belongsTo(SpecialReliefRequest::class, 'special_relief_request_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
