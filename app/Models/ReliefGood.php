<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReliefGood extends Model
{
    protected $fillable = ['name', 'unit', 'category'];

    public function inventories(): HasMany
    {
        return $this->hasMany(ReliefInventory::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(ReliefTransaction::class);
    }
}
