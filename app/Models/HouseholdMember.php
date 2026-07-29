<?php

namespace App\Models;

use App\Support\AgeTier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HouseholdMember extends Model
{
    // 'is_present' was added by the Barangay Personnel migration and must be
    // fillable, otherwise Eloquent create()/update() silently drops it and every
    // member is stored as not-present (breaking the headcount).
    //
    // 'age_tier_fallback' (Phase 2 item 5) is subject to the same rule -- if it
    // is missing here the manual age-group dropdown appears to work and saves
    // nothing, which is exactly how the tag bug went unnoticed for a phase.
    protected $fillable = [
        'household_id', 'full_name', 'age', 'birthdate', 'age_tier_fallback', 'sex',
        'family_role', 'is_household_head', 'is_present', 'contact_number',
    ];

    protected function casts(): array
    {
        return [
            'birthdate' => 'date',
            'is_household_head' => 'boolean',
            'is_present' => 'boolean',
        ];
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function vulnerabilities(): HasMany
    {
        return $this->hasMany(MemberVulnerability::class);
    }

    public function vulnerableClassifications(): BelongsToMany
    {
        return $this->belongsToMany(
            VulnerableClassification::class,
            'member_vulnerabilities'
        )->withPivot(['details', 'tagged_by', 'tagged_at'])->withTimestamps();
    }

    /**
     * Only the tags that still count.
     *
     * Senior Citizen and Infant / Young Child were retired when they became age
     * tiers, but their historical rows are still in the pivot. Anything that
     * reports a number -- dashboard KPIs, charts, report filters -- must go
     * through this relation, or retired age tags get double-counted as
     * vulnerabilities alongside the tier they became.
     */
    public function activeClassifications(): BelongsToMany
    {
        return $this->vulnerableClassifications()
            ->where('vulnerable_classifications.is_selectable', true);
    }

    public function isVulnerable(): bool
    {
        return $this->activeClassifications()->exists();
    }

    // ---------------------------------------------------------------
    // Age tier (Phase 2 item 5). Derived on read, never stored.
    // ---------------------------------------------------------------

    /** Whole months old, or null when there is no birthdate on file. */
    public function ageInMonths(): ?int
    {
        return AgeTier::monthsSince($this->birthdate);
    }

    /** The tier KEY. Birthdate wins; the manual fallback is the last resort. */
    public function ageTier(): string
    {
        return AgeTier::forMember($this);
    }

    public function ageTierLabel(): string
    {
        return AgeTier::label($this->ageTier());
    }

    public function ageTierShortLabel(): string
    {
        return AgeTier::shortLabel($this->ageTier());
    }

    /**
     * Age in whole years for display only.
     *
     * Prefers birthdate, falls back to the stored 'age' column for legacy rows
     * registered before birthdate existed. Never use this for tiering -- it
     * cannot tell an Infant from a Toddler.
     */
    public function displayAge(): ?int
    {
        $months = $this->ageInMonths();

        if ($months !== null) {
            return intdiv($months, 12);
        }

        return $this->age !== null ? (int) $this->age : null;
    }

    /**
     * Adds a 'tier' column to a query so results can be grouped in SQL.
     *
     * DO NOT group directly by the CASE expression -- MySQL's ONLY_FULL_GROUP_BY
     * rejects it with error 1055 ("household_members.birthdate isn't in GROUP
     * BY"), because it cannot prove functional dependency through a nested CASE
     * over TIMESTAMPDIFF. Use AgeTier::sexMatrixFor(), which wraps the same
     * expression in a derived table:
     *
     *   AgeTier::sexMatrixFor(
     *       HouseholdMember::query()->where('is_present', true)
     *   );
     *
     * This scope stays useful for selecting the tier on rows you are NOT
     * grouping (single-record display, per-row report columns).
     */
    public function scopeWithAgeTier(Builder $query, string $table = 'household_members'): Builder
    {
        return $query->selectRaw(AgeTier::sqlCase($table) . ' as tier');
    }
}
