<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    protected $fillable = ['name', 'display_name'];

    // Constant helpers so role checks never rely on magic strings scattered in code
    public const SUPER_ADMIN = 'super_admin';
    public const CITY_ADMIN = 'city_admin';
    public const BARANGAY_PERSONNEL = 'barangay_personnel';

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
