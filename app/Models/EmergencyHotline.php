<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmergencyHotline extends Model
{
    protected $fillable = ['label', 'number', 'category', 'description', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public static function categoryLabel(string $category): string
    {
        return match ($category) {
            'disaster' => 'Disaster and Rescue',
            'police' => 'Police',
            'fire' => 'Fire',
            'medical' => 'Medical and Health',
            'utilities' => 'Utilities',
            default => 'Other',
        };
    }
}
