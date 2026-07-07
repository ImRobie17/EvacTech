<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HouseholdMember extends Model
{
    protected $fillable = [
        'household_id', 'full_name', 'age', 'birthdate', 'sex',
        'family_role', 'is_household_head', 'contact_number',
    ];

    protected function casts(): array
    {
        return [
            'birthdate' => 'date',
            'is_household_head' => 'boolean',
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

    public function isVulnerable(): bool
    {
        return $this->vulnerabilities()->exists();
    }
}
