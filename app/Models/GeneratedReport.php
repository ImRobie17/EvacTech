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
            // Phase 3 item 11b. Both ARE in ReportController::TYPES, unlike the
            // IDP form below -- they are ordinary flat-table reports that the
            // generic dropdown, the xlsx export and reports/pdf/generic.blade.php
            // all handle unchanged.
            'demographics' => 'Evacuee Demographics',
            'shelter_ranking' => 'Shelter Ranking',
            // Phase 3 item 11a. Registered HERE but deliberately NOT in either
            // ReportController::TYPES: TYPES is the whitelist for the generic
            // dropdown, which also offers xlsx and routes into buildDataset().
            // The IDP form has its own route, its own view and is PDF only.
            'idp_monitoring' => 'CSWDO IDP Monitoring Form',
            default => ucfirst(str_replace('_', ' ', $type)),
        };
    }
}
