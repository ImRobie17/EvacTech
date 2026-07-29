<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VulnerableClassification extends Model
{
    protected $fillable = ['code', 'name', 'description', 'is_selectable'];

    protected function casts(): array
    {
        return ['is_selectable' => 'boolean'];
    }

    /**
     * The five categories printed in table 2 of the CSWDO IDP Monitoring Form,
     * in the order the form lists them.
     *
     * An explicit whitelist, NOT "everything selectable". Person with Chronic
     * Illness is a legitimate live tag that simply is not on the official form,
     * and a future internal tag must not silently appear on a document a City
     * Social Welfare officer signs.
     *
     * Single Headed Household is deliberately absent: it is a household-level
     * fact derived from members_present == 1, never a per-member tag.
     */
    public const REPORTABLE_CODES = ['pwd', 'pregnant', 'lactating', 'solo_parent', 'fourps'];

    public function memberVulnerabilities(): HasMany
    {
        return $this->hasMany(MemberVulnerability::class);
    }

    /** Everything a staff member is allowed to tag today. */
    public function scopeSelectable(Builder $query): Builder
    {
        return $query->where('is_selectable', true);
    }

    /** Only the five on the official form, in form order. */
    public function scopeReportable(Builder $query): Builder
    {
        return $query
            ->whereIn('code', self::REPORTABLE_CODES)
            ->orderByRaw(
                'FIELD(code, ' . implode(', ', array_fill(0, count(self::REPORTABLE_CODES), '?')) . ')',
                self::REPORTABLE_CODES
            );
    }

    public function isReportable(): bool
    {
        return in_array($this->code, self::REPORTABLE_CODES, true);
    }
}
