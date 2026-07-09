<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GeneratedReport extends Model
{
    protected $fillable = [
        'report_type', 'format', 'date_from', 'date_to', 'file_path', 'generated_by', 'evacuation_center_id',
    ];

    protected function casts(): array
    {
        return [
            'date_from' => 'date',
            'date_to' => 'date',
        ];
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function evacuationCenter(): BelongsTo
    {
        return $this->belongsTo(EvacuationCenter::class);
    }

    public static function typeLabel(string $type): string
    {
        return match ($type) {
            'household_registry' => 'Household Registry',
            'attendance' => 'Attendance / Headcount',
            'relief' => 'Relief Distribution',
            'vulnerable' => 'Vulnerable Population',
            'occupancy' => 'Shelter Occupancy Summary',
            default => ucfirst(str_replace('_', ' ', $type)),
        };
    }
}
