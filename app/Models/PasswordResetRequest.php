<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PHASE 7 ITEM 5 -- a staff member asking an administrator to reset their
 * password.
 *
 * The queue only. Confirming the requester's identity happens by telephone,
 * off system, and the reset itself uses the password field that already exists
 * in both account editors.
 */
class PasswordResetRequest extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_DISMISSED = 'dismissed';

    /**
     * Every column an application code path writes. Leaving one out here is the
     * bug that made the headcount stick at 0 in Phase 2: Laravel drops the value
     * silently and nothing in the log says why.
     */
    protected $fillable = [
        'user_id',
        'contact_number',
        'status',
        'handled_by',
        'handled_at',
        'requested_ip',
    ];

    protected function casts(): array
    {
        return ['handled_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Pending requests raised by accounts holding one of these roles.
     *
     * This is the routing rule in one place: barangay requests are City Admin's
     * to handle, City Admin requests are Super Admin's. Both index screens call
     * it with their own list rather than writing the whereHas themselves.
     *
     * @param  array<int, string>  $roleNames
     */
    public function scopeForRoles(Builder $query, array $roleNames): Builder
    {
        return $query->whereHas('user.role', fn ($q) => $q->whereIn('name', $roleNames));
    }
}
