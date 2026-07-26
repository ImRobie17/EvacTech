<?php

namespace App\Http\Controllers\Barangay;

use App\Exports\ArrayExport;
use App\Models\GeneratedReport;
use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\ReliefTransaction;
use App\Services\AuditLogger;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class ReportController extends BarangayController
{
    public const TYPES = ['household_registry', 'attendance', 'relief', 'vulnerable', 'occupancy'];

    public function index()
    {
        $center = $this->center();

        $recent = GeneratedReport::with('generatedBy')
            ->when($center, fn ($q) => $q->where('evacuation_center_id', $center->id))
            ->where('generated_by', auth()->id())
            ->latest()
            ->take(8)
            ->get();

        return view('barangay.reports.index', compact('center', 'recent'));
    }

    public function generate(Request $request)
    {
        $data = $request->validate([
            'report_type' => ['required', 'in:' . implode(',', self::TYPES)],
            'format' => ['required', 'in:pdf,xlsx'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $center = $this->centerOrFail();
        [$headings, $rows, $title] = $this->buildDataset($data['report_type'], $data['date_from'] ?? null, $data['date_to'] ?? null, $center);

        $record = GeneratedReport::create([
            'report_type' => $data['report_type'],
            'format' => $data['format'],
            'date_from' => $data['date_from'] ?? null,
            'date_to' => $data['date_to'] ?? null,
            'generated_by' => auth()->id(),
            'evacuation_center_id' => $center->id,
        ]);

        AuditLogger::log('created', $record, "Generated {$title} report ({$data['format']})");

        $filename = str_replace(' ', '_', strtolower($title)) . '_' . now()->format('Ymd_His');

        if ($data['format'] === 'xlsx') {
            return Excel::download(new ArrayExport($headings, $rows), $filename . '.xlsx');
        }

        $pdf = Pdf::loadView('reports.pdf.generic', [
            'title' => $title,
            'center' => $center,
            'headings' => $headings,
            'rows' => $rows,
            'from' => $data['date_from'] ?? null,
            'to' => $data['date_to'] ?? null,
            'generatedBy' => auth()->user()->name,
        ])->setPaper('a4', 'landscape');

        return $pdf->download($filename . '.pdf');
    }

    /** @return array{0: string[], 1: array[], 2: string} */
    private function buildDataset(string $type, ?string $from, ?string $to, $center): array
    {
        $range = fn ($q, $col = 'created_at') => $q
            ->when($from, fn ($q) => $q->whereDate($col, '>=', $from))
            ->when($to, fn ($q) => $q->whereDate($col, '<=', $to));

        return match ($type) {
            'household_registry' => [
                ['Household ID', 'Household Head', 'Family Size', 'Origin Barangay', 'Address', 'Status', 'Shelter', 'Registered On'],
                // RESCOPED from origin_barangay_id to the shelter being operated:
                // staff are assigned to shelters, not barangays. Households not yet
                // placed in a shelter are included so the registry stays complete.
                $range(Household::with(['headMember', 'evacuationCenter', 'originBarangay'])
                    ->where(fn ($q) => $q
                        ->where('evacuation_center_id', $center->id)
                        ->orWhereNull('evacuation_center_id')))
                    ->get()
                    ->map(fn ($h) => [
                        $h->household_code,
                        $h->headMember?->full_name ?? '-',
                        $h->number_of_members,
                        $h->originBarangay?->name ?? '-',
                        $h->origin_address,
                        ucfirst(str_replace('_', ' ', $h->status)),
                        $h->evacuationCenter?->name ?? '-',
                        $h->created_at->format('M d, Y'),
                    ])->all(),
                'Household Registry',
            ],
            'attendance' => [
                ['Household ID', 'Household Head', 'Members Present', 'Status', 'Checked In', 'Checked Out'],
                $range(Household::with('headMember')
                    ->where('evacuation_center_id', $center->id), 'checked_in_at')
                    ->whereNotNull('checked_in_at')
                    ->get()
                    ->map(fn ($h) => [
                        $h->household_code,
                        $h->headMember?->full_name ?? '-',
                        $h->members_present,
                        ucfirst(str_replace('_', ' ', $h->status)),
                        $h->checked_in_at?->format('M d, Y h:i A'),
                        $h->checked_out_at?->format('M d, Y h:i A') ?? '-',
                    ])->all(),
                'Attendance Headcount',
            ],
            'relief' => [
                ['Date', 'Household Head', 'Relief Good', 'Quantity', 'Distributed By', 'Remarks'],
                $range(ReliefTransaction::with(['household.headMember', 'reliefGood', 'recordedBy'])
                    ->where('evacuation_center_id', $center->id)
                    ->where('type', 'distributed'), 'transaction_date')
                    ->latest('transaction_date')
                    ->get()
                    ->map(fn ($t) => [
                        $t->transaction_date->format('M d, Y'),
                        $t->household?->headMember?->full_name ?? '-',
                        $t->reliefGood->name,
                        $t->quantity . ' ' . $t->reliefGood->unit,
                        $t->recordedBy?->name ?? '-',
                        $t->remarks ?? '',
                    ])->all(),
                'Relief Distribution',
            ],
            'vulnerable' => [
                ['Name', 'Age', 'Sex', 'Household', 'Classifications', 'Currently Present'],
                HouseholdMember::with(['household', 'vulnerableClassifications'])
                    ->whereHas('household', fn ($q) => $q->where('evacuation_center_id', $center->id))
                    ->whereHas('vulnerabilities')
                    ->get()
                    ->map(fn ($m) => [
                        $m->full_name,
                        $m->age,
                        ucfirst((string) $m->sex),
                        $m->household->household_code,
                        $m->vulnerableClassifications->pluck('name')->implode(', '),
                        $m->is_present ? 'Yes' : 'No',
                    ])->all(),
                'Vulnerable Population',
            ],
            'occupancy' => [
                ['Shelter', 'Capacity', 'Current Occupancy', 'Occupancy %', 'Households Checked-in', 'Status'],
                [[
                    $center->name,
                    $center->capacity,
                    $center->current_occupancy,
                    ($center->capacity > 0 ? round($center->current_occupancy / $center->capacity * 100) : 0) . '%',
                    Household::where('evacuation_center_id', $center->id)->where('status', 'checked_in')->count(),
                    $center->statusLabel(),
                ]],
                'Shelter Occupancy Summary',
            ],
        };
    }
}
