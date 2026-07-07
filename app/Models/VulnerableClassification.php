<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VulnerableClassification extends Model
{
    protected $fillable = ['name', 'description'];

    public function memberVulnerabilities(): HasMany
    {
        return $this->hasMany(MemberVulnerability::class);
    }
}
