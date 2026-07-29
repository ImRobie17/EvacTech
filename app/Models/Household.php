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

    /**
     * Phase 2 item 6 -- "Single Headed Household".
     *
     * DERIVED, never stored, and deliberately NOT a vulnerable classification.
     * member_vulnerabilities is a per-MEMBER pivot; putting a household-level
     * fact there would force a choice about which member carries it and would
     * corrupt every count grouped by classification.
     *
     * Definition confirmed with Cabuyao's shelter operations manager: a
     * one-person family who is currently checked in at the shelter. Because it
     * is tied to check-in state, the flag correctly does not exist before
     * check-in, and clears itself if the family checks out or the rest of them
     * arrive.
     */
    public function isSingleHeaded(): bool
    {
        return $this->status === 'checked_in' && (int) $this->members_present === 1;
    }

    /** Query-side twin of isSingleHeaded(), for counts and report cross-tabs. */
    public function scopeSingleHeaded($query)
    {
        return $query->where('status', 'checked_in')->where('members_present', 1);
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

    /**
     * PHASE 2 ITEM 8 -- shelter-to-shelter moves.
     *
     * Separate from transfers() above, which is family-HEAD-role changes and
     * always has from_center_id == to_center_id. The two answer different
     * questions and share no lifecycle.
     */
    public function shelterTransfers(): HasMany
    {
        return $this->hasMany(ShelterTransfer::class);
    }

    /**
     * The transfer currently in flight for this family, if any.
     *
     * Guard used before starting a second transfer and before allowing a
     * check-out: a family who is committed to a move must not be checked out
     * from underneath it, or the transfer would try to receive a household that
     * is no longer anywhere.
     */
    public function openTransfer(): ?ShelterTransfer
    {
        return $this->shelterTransfers()
            ->whereIn('status', ShelterTransfer::OPEN_STATUSES)
            ->latest('id')
            ->first();
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
