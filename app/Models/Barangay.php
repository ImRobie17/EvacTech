<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Barangay extends Model
{
    protected $fillable = ['name', 'code', 'risk_level', 'latitude', 'longitude'];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function evacuationCenters(): HasMany
    {
        return $this->hasMany(EvacuationCenter::class);
    }

    public function households(): HasMany
    {
        return $this->hasMany(Household::class, 'origin_barangay_id');
    }
}
