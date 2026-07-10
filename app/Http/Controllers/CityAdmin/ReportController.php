<?php

namespace App\Http\Controllers\CityAdmin;

use App\Exports\ArrayExport;
use App\Models\EvacuationCenter;
use App\Models\GeneratedReport;
use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\ReliefTransaction;
use App\Services\AuditLogger;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class ReportController extends \App\Http\Controllers\Controller
{
    public const TYPES = ['household_registry', 'attendance', 'relief', 'vulnerable', 'occupancy'];

    public function index()
    {
        $shelters = EvacuationCenter::orderBy('name')->get();
        $recent = GeneratedReport::with('generatedBy')
            ->where('generated_by', auth()->id())
            ->latest()->take(8)->get();

        return view('cityadmin.reports.index', compact('shelters', 'recent'));
    }

    public function generate(Request $request)
    {
        $data = $request->validate([
            'report_type' => ['required', 'in:' . implode(',', self::TYPES)],
            'format' => ['required', 'in:pdf,xlsx'],
            'center' => ['nullable', 'exists:evacuation_centers,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        // null center = city-wide (all shelters)
        $center = ! empty($data['center']) ? EvacuationCenter::find($data['center']) : null;

        [$headings, $rows, $title] = $this->buildDataset(
            $data['report_type'], $data['date_from'] ?? null, $data['date_to'] ?? null, $center
        );

        $record = GeneratedReport::create([
            'report_type' => $data['report_type'],
            'format' => $data['format'],
            'date_from' => $data['date_from'] ?? null,
            'date_to' => $data['date_to'] ?? null,
            'generated_by' => auth()->id(),
            'evacuation_center_id' => $center?->id,
        ]);

        $scope = $center ? $center->name : 'City-wide';
        AuditLogger::log('created', $record, "Generated {$title} report ({$scope}, {$data['format']})");

        $filename = str_replace(' ', '_', strtolower($title)) . '_' . now()->format('Ymd_His');

        if ($data['format'] === 'xlsx') {
            return Excel::download(new ArrayExport($headings, $rows), $filename . '.xlsx');
        }

        $pdf = Pdf::loadView('reports.pdf.generic', [
            'title' => $title,
            'center' => $center,
            'scopeLabel' => $scope,
            'headings' => $headings,
            'rows' => $rows,
            'from' => $data['date_from'] ?? null,
            'to' => $data['date_to'] ?? null,
            'generatedBy' => auth()->user()->name,
        ])->setPaper('a4', 'landscape');

        return $pdf->download($filename . '.pdf');
    }

    /**
     * Same datasets as the barangay report, but $center may be null to mean
     * "all shelters city-wide". When null, center-scoped queries drop that
     * where-clause.
     *
     * @return array{0: string[], 1: array[], 2: string}
     */
    private function buildDataset(string $type, ?string $from, ?string $to, ?EvacuationCenter $center): array
    {
        $range = fn ($q, $col = 'created_at') => $q
            ->when($from, fn ($q) => $q->whereDate($col, '>=', $from))
            ->when($to, fn ($q) => $q->whereDate($col, '<=', $to));

        $centerFilter = fn ($q, $col = 'evacuation_center_id') => $q->when($center, fn ($q) => $q->where($col, $center->id));

        return match ($type) {
            'household_registry' => [
                ['Household ID', 'Household Head', 'Family Size', 'Barangay', 'Address', 'Status', 'Shelter', 'Registered On'],
                $range(Household::with(['headMember', 'originBarangay', 'evacuationCenter'])
                    ->when($center, fn ($q) => $q->where('evacuation_center_id', $center->id)))
                    ->get()->map(fn ($h) => [
                        $h->household_code, $h->headMember?->full_name ?? '—', $h->number_of_members,
                        $h->originBarangay?->name, $h->origin_address,
                        ucfirst(str_replace('_', ' ', $h->status)), $h->evacuationCenter?->name ?? '—',
                        $h->created_at->format('M d, Y'),
                    ])->all(),
                'Household Registry',
            ],
            'attendance' => [
                ['Household ID', 'Household Head', 'Shelter', 'Members Present', 'Status', 'Checked In', 'Checked Out'],
                $centerFilter($range(Household::with(['headMember', 'evacuationCenter'])
                    ->whereNotNull('checked_in_at'), 'checked_in_at'))
                    ->get()->map(fn ($h) => [
                        $h->household_code, $h->headMember?->full_name ?? '—', $h->evacuationCenter?->name ?? '—',
                        $h->members_present, ucfirst(str_replace('_', ' ', $h->status)),
                        $h->checked_in_at?->format('M d, Y h:i A'), $h->checked_out_at?->format('M d, Y h:i A') ?? '—',
                    ])->all(),
                'Attendance Headcount',
            ],
            'relief' => [
                ['Date', 'Shelter', 'Household Head', 'Relief Good', 'Quantity', 'Distributed By'],
                $centerFilter($range(ReliefTransaction::with(['household.headMember', 'evacuationCenter', 'reliefGood', 'recordedBy'])
                    ->where('type', 'distributed'), 'transaction_date'))
                    ->latest('transaction_date')->get()->map(fn ($t) => [
                        $t->transaction_date->format('M d, Y'), $t->evacuationCenter?->name ?? '—',
                        $t->household?->headMember?->full_name ?? '—', $t->reliefGood->name,
                        $t->quantity . ' ' . $t->reliefGood->unit, $t->recordedBy?->name ?? '—',
                    ])->all(),
                'Relief Distribution',
            ],
            'vulnerable' => [
                ['Name', 'Age', 'Sex', 'Household', 'Shelter', 'Classifications', 'Present'],
                HouseholdMember::with(['household.evacuationCenter', 'vulnerableClassifications'])
                    ->whereHas('vulnerabilities')
                    ->when($center, fn ($q) => $q->whereHas('household', fn ($q) => $q->where('evacuation_center_id', $center->id)))
                    ->get()->map(fn ($m) => [
                        $m->full_name, $m->age, ucfirst((string) $m->sex),
                        $m->household->household_code, $m->household->evacuationCenter?->name ?? '—',
                        $m->vulnerableClassifications->pluck('name')->implode(', '),
                        $m->is_present ? 'Yes' : 'No',
                    ])->all(),
                'Vulnerable Population',
            ],
            'occupancy' => [
                ['Shelter', 'Barangay', 'Capacity', 'Occupancy', 'Occupancy %', 'Households', 'Status'],
                EvacuationCenter::with('barangay')
                    ->when($center, fn ($q) => $q->whereKey($center->id))
                    ->get()->map(fn ($c) => [
                        $c->name, $c->barangay?->name, $c->capacity, $c->current_occupancy,
                        ($c->capacity > 0 ? round($c->current_occupancy / $c->capacity * 100) : 0) . '%',
                        Household::where('evacuation_center_id', $c->id)->where('status', 'checked_in')->count(),
                        ucfirst($c->status),
                    ])->all(),
                'Shelter Occupancy Summary',
            ],
        };
    }
}
