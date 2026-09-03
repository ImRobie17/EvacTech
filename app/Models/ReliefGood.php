<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReliefGood extends Model
{
    /**
     * DROP B1. `is_monetary` added -- see the migration for why the unit
     * counters have to exclude these goods.
     *
     * `unit` stays and stays REQUIRED. It is what makes "Rice 50kg" and "Rice
     * 5kg" two separate stock lines instead of one pooled number
     * that cannot answer whether the last sack will serve two families
     * expecting bags. Pack size lives in the item, not in the transaction,
     * because relief_inventories is keyed on (shelter, item) -- putting the
     * unit on the transaction would record the history correctly while leaving
     * the stock figure just as wrong.
     */
    protected $fillable = ['name', 'unit', 'category', 'is_monetary'];

    protected function casts(): array
    {
        return ['is_monetary' => 'boolean'];
    }

    /**
     * Physical goods -- everything whose quantity is a count of things.
     *
     * Used by every unit-based figure on both relief screens. Written as a
     * scope rather than a hand-rolled where() at each call site because there
     * are seven of them across four controllers, and a rule copied seven times
     * is a rule that has already started to drift.
     */
    public function scopeCountable(Builder $query): Builder
    {
        return $query->where('is_monetary', false);
    }

    /** Display name with its unit, e.g. "Rice (50kg sack)  sack". */
    public function labelWithUnit(): string
    {
        return $this->unit ? $this->name . ' (' . $this->unit . ')' : $this->name;
    }

    public function inventories(): HasMany
    {
        return $this->hasMany(ReliefInventory::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(ReliefTransaction::class);
    }
}
