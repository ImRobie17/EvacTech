<?php

namespace App\Http\Controllers\CityAdmin;

use App\Exports\ArrayExport;
use App\Http\Controllers\Concerns\FiltersReports;
use App\Http\Controllers\Concerns\RendersIdpForm;
use App\Models\EvacuationCenter;
use App\Models\GeneratedReport;
use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\ReliefTransaction;
use App\Services\AuditLogger;
use App\Support\IdpForm;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class ReportController extends \App\Http\Controllers\Controller
{
    use FiltersReports;
    use RendersIdpForm;

    /**
     * PHASE 3 ITEM 11b added the last two.
     *
     * 'demographics' is every member in scope, not only the tagged ones, so the
     * three demographic filters have something to filter that is not already
     * restricted to vulnerable people.
     *
     * 'shelter_ranking' is City Admin only and deliberately absent from the
     * barangay list: barangay staff operate one shelter at a time, and a
     * ranking of one row is not a report.
     *
     * PHASE 11 added 'shelter_demographic_summary' -- aggregated counts by
     * vulnerability category for a specific shelter, as requested by professor.
     */
    public const TYPES = [
        'household_registry', 'attendance', 'relief', 'vulnerable',
        'occupancy', 'demographics', 'shelter_ranking', 'shelter_demographic_summary',
    ];

    public function index()
    {
        $shelters = EvacuationCenter::orderBy('name')->get();
        $recent = GeneratedReport::with('generatedBy')
            ->where('generated_by', auth()->id())
            ->latest()->take(8)->get();

        // Pre-fill for the IDP panel's two "Number of Affected ..." inputs.
        // City-wide, matching that panel's default "All shelters (accumulated)"
        // selection. Labelled in the view as a live headcount, because it is not
        // the city-wide disaster figure the official field means.
        $idpCounts = IdpForm::headcount();

        // Item 11b. Prepared here rather than read from the class in Blade --
        // controllers prepare, views render.
        $filterOptions = $this->reportFilterOptions();

        return view('cityadmin.reports.index', compact('shelters', 'recent', 'idpCounts', 'filterOptions'));
    }

    /**
     * The CSWDO IDP Monitoring Form (Phase 3 item 11a).
     *
     * A SEPARATE action, deliberately not another entry in self::TYPES. TYPES is
     * the whitelist for the generic dropdown, which also offers an xlsx radio
     * and routes into buildDataset(); this form is a fixed two-cross-tab layout,
     * so an xlsx of it would be a broken artifact. PDF only, its own route, its
     * own view, and the working report types are left untouched.
     *
     * SCOPE. A shelter id prints that one centre, which is how CSWDO fills the
     * sheet in by hand. Leaving it blank prints every shelter accumulated, with
     * the header reading "Accumulated Shelters" -- a city-level roll-up the
     * paper form has no equivalent of, and the reason the barangay side has no
     * such option.
     */
    public function idp(Request $request)
    {
        $data = $request->validate([
            'center' => ['nullable', 'exists:evacuation_centers,id'],
            'disaster_name' => ['required', 'string', 'max:150'],
            'disaster_date' => ['required', 'date', 'before_or_equal:today'],
            'affected_families' => ['nullable', 'integer', 'min:0', 'max:9999999'],
            'affected_persons' => ['nullable', 'integer', 'min:0', 'max:9999999'],
        ], [
            'disaster_name.required' => 'Enter the name of the disaster, e.g. Typhoon Kristine.',
            'disaster_date.required' => 'Enter the date the disaster occurred.',
        ]);

        // Empty center = accumulate every shelter. The barangay relation is
        // eager-loaded because the header block prints the shelter's barangay.
        $center = ! empty($data['center'])
            ? EvacuationCenter::with('barangay')->find($data['center'])
            : null;

        $form = $center ? IdpForm::forCenter($center) : IdpForm::accumulated();

        $record = GeneratedReport::create([
            'report_type' => IdpForm::TYPE,
            'format' => 'pdf',
            'generated_by' => auth()->id(),
            'evacuation_center_id' => $center?->id,
        ]);

        $scope = $center ? $center->name : IdpForm::ACCUMULATED_LABEL;
        AuditLogger::log('created', $record,
            "Generated IDP Monitoring Form for {$scope} (disaster: {$data['disaster_name']})");

        return $this->renderIdpPdf($data, $form);
    }

    public function generate(Request $request)
    {
        $data = $request->validate(array_merge([
            'report_type' => ['required', 'in:' . implode(',', self::TYPES)],
            'format' => ['required', 'in:pdf,xlsx'],
            'center' => ['nullable', 'exists:evacuation_centers,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ], $this->reportFilterRules()));

        // null center = city-wide (all shelters)
        $center = ! empty($data['center']) ? EvacuationCenter::find($data['center']) : null;

        $filters = $this->reportFilters($data);

        [$headings, $rows, $title] = $this->buildDataset(
            $data['report_type'], $data['date_from'] ?? null, $data['date_to'] ?? null, $center, $filters
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
        $filterLabels = $this->reportFilterLabels($filters);

        // The filters are NOT persisted on generated_reports -- that would be a
        // migration for a column nothing reads back. They are recorded here,
        // where an auditor can already see who generated what and when.
        $note = $filterLabels === [] ? '' : ('; filters: ' . implode(' | ', $filterLabels));
        AuditLogger::log('created', $record, "Generated {$title} report ({$scope}, {$data['format']}{$note})");

        $filename = str_replace(' ', '_', strtolower($title))
            . $this->reportFilterSlug($filters)
            . '_' . now()->format('Ymd_His');

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
            'filters' => $filterLabels,
            'filterNote' => $this->reportFilterNote($data['report_type'], $filters),
            'generatedBy' => auth()->user()->name,
        ])->setPaper('a4', 'landscape');

        return $pdf->download($filename . '.pdf');
    }

    /**
     * Same datasets as the barangay report, but $center may be null to mean
     * "all shelters city-wide". When null, center-scoped queries drop that
     * where-clause.
     *
     * $filters is [] for an unfiltered report, in which case every query and
     * every heading row below behaves exactly as it did before item 11b.
     *
     * @return array{0: string[], 1: array[], 2: string}
     */
    private function buildDataset(
        string $type,
        ?string $from,
        ?string $to,
        ?EvacuationCenter $center,
        array $filters = []
    ): array {
        $range = fn ($q, $col = 'created_at') => $q
            ->when($from, fn ($q) => $q->whereDate($col, '>=', $from))
            ->when($to, fn ($q) => $q->whereDate($col, '<=', $to));

        $centerFilter = fn ($q, $col = 'evacuation_center_id') => $q->when($center, fn ($q) => $q->where($col, $center->id));

        return match ($type) {
            'household_registry' => [
                $this->withMatchColumn(
                    ['Household ID', 'Household Head', 'Family Size', 'Barangay', 'Address', 'Status', 'Shelter', 'Registered On'],
                    $filters, 'Matching Members'
                ),
                $range($this->filterHouseholds(
                    Household::with(['headMember', 'originBarangay', 'evacuationCenter'])
                        ->when($center, fn ($q) => $q->where('evacuation_center_id', $center->id)),
                    $filters
                ))->get()->map(fn ($h) => $this->withMatchColumn([
                    $h->household_code, $h->headMember?->full_name ?? '-', $h->number_of_members,
                    $h->originBarangay?->name, $h->origin_address,
                    ucfirst(str_replace('_', ' ', $h->status)), $h->evacuationCenter?->name ?? '-',
                    $h->created_at->format('M d, Y'),
                ], $filters, $h))->all(),
                'Household Registry',
            ],
            'attendance' => [
                $this->withMatchColumn(
                    ['Household ID', 'Household Head', 'Shelter', 'Members Present', 'Status', 'Checked In', 'Checked Out'],
                    $filters, 'Matching Members'
                ),
                $centerFilter($range($this->filterHouseholds(
                    Household::with(['headMember', 'evacuationCenter'])->whereNotNull('checked_in_at'),
                    $filters
                ), 'checked_in_at'))
                    ->get()->map(fn ($h) => $this->withMatchColumn([
                        $h->household_code, $h->headMember?->full_name ?? '-', $h->evacuationCenter?->name ?? '-',
                        $h->members_present, ucfirst(str_replace('_', ' ', $h->status)),
                        $h->checked_in_at?->format('M d, Y h:i A'), $h->checked_out_at?->format('M d, Y h:i A') ?? '-',
                    ], $filters, $h))->all(),
                'Attendance Headcount',
            ],
            'relief' => [
                ['Date', 'Shelter', 'Household Head', 'Relief Good', 'Quantity', 'Distributed By'],
                $centerFilter($range($this->filterRelief(
                    ReliefTransaction::with(['household.headMember', 'evacuationCenter', 'reliefGood', 'recordedBy'])
                        ->where('type', 'distributed'),
                    $filters
                ), 'transaction_date'))
                    ->latest('transaction_date')->get()->map(fn ($t) => [
                        $t->transaction_date->format('M d, Y'), $t->evacuationCenter?->name ?? '-',
                        $t->household?->headMember?->full_name ?? '-', $t->reliefGood->name,
                        $t->quantity . ' ' . $t->reliefGood->unit, $t->recordedBy?->name ?? '-',
                    ])->all(),
                'Relief Distribution',
            ],

            // ITEM 11b FIX. This used to select on whereHas('vulnerabilities')
            // and print vulnerableClassifications->pluck('name'), so a member
            // whose only tag was a RETIRED category still appeared, and retired
            // labels printed beside live ones. activeClassifications is the
            // relation HouseholdMember provides for exactly this, and the whole
            // codebase keys on code rather than name.
            //
            // The Age column also used to print $m->age alone, which is null for
            // anyone registered by age group with no birthdate -- a blank cell in
            // a signed report. The derived tier is authoritative and always
            // present, so it gets its own column and the numeric age falls back
            // to a dash.
            'vulnerable' => [
                ['Name', 'Age', 'Age Group', 'Sex', 'Household', 'Shelter', 'Categories', 'Present'],
                $range($this->filterMembers(
                    HouseholdMember::with(['household.evacuationCenter', 'activeClassifications'])
                        ->whereHas('activeClassifications')
                        ->when($center, fn ($q) => $q->whereHas('household', fn ($q) => $q->where('evacuation_center_id', $center->id))),
                    $filters
                ), 'household_members.created_at')->get()->map(fn ($m) => [
                    $m->full_name, $m->age ?? '-', $m->ageTierLabel(), ucfirst((string) $m->sex),
                    $m->household?->household_code ?? '-', $m->household?->evacuationCenter?->name ?? '-',
                    $m->activeClassifications->pluck('name')->implode(', '),
                    $m->is_present ? 'Yes' : 'No',
                ])->all(),
                'Vulnerable Population',
            ],

            // ITEM 11b. Every member in scope, tagged or not. Vulnerable
            // Population answers "who needs special handling"; this answers
            // "who is here", which is the question the three filters are for.
            'demographics' => [
                ['Name', 'Age', 'Age Group', 'Sex', 'Household', 'Shelter', 'Categories', 'Present'],
                $range($this->filterMembers(
                    HouseholdMember::with(['household.evacuationCenter', 'activeClassifications'])
                        ->when($center, fn ($q) => $q->whereHas('household', fn ($q) => $q->where('evacuation_center_id', $center->id))),
                    $filters
                ), 'household_members.created_at')->get()->map(fn ($m) => [
                    $m->full_name, $m->age ?? '-', $m->ageTierLabel(), ucfirst((string) $m->sex),
                    $m->household?->household_code ?? '-', $m->household?->evacuationCenter?->name ?? '-',
                    $m->activeClassifications->pluck('name')->implode(', ') ?: '-',
                    $m->is_present ? 'Yes' : 'No',
                ])->all(),
                'Evacuee Demographics',
            ],

            // Occupancy is a live snapshot, so the date range does not apply to
            // this report or to the ranking below -- it never did.
            'occupancy' => [
                $this->withMatchColumn(
                    ['Shelter', 'Barangay', 'Capacity', 'Occupancy', 'Occupancy %', 'Households', 'Status'],
                    $filters, 'Matching Members'
                ),
                $this->filterShelters(
                    EvacuationCenter::with('barangay')->when($center, fn ($q) => $q->whereKey($center->id)),
                    $filters
                )->get()->map(fn ($c) => $this->withMatchColumn([
                    $c->name, $c->barangay?->name, $c->capacity, $c->current_occupancy,
                    ($c->capacity > 0 ? round($c->current_occupancy / $c->capacity * 100) : 0) . '%',
                    Household::where('evacuation_center_id', $c->id)->where('status', 'checked_in')->count(),
                    $c->statusLabel(),
                ], $filters, $c))->all(),
                'Shelter Occupancy Summary',
            ],

            // ITEM 11b -- the roadmap's "top shelters / most-least data".
            //
            // Sorted in PHP rather than SQL: occupancy % is capacity-relative and
            // capacity can be zero, so ordering it in the database would mean a
            // division guard in raw SQL for the sake of ranking a few dozen rows.
            'shelter_ranking' => [
                $this->withMatchColumn(
                    ['Rank', 'Shelter', 'Barangay', 'Capacity', 'Occupancy', 'Occupancy %', 'Households', 'Status'],
                    $filters, 'Matching Members'
                ),
                $this->filterShelters(
                    EvacuationCenter::with('barangay')->when($center, fn ($q) => $q->whereKey($center->id)),
                    $filters
                )->get()
                    ->map(fn ($c) => [
                        'center' => $c,
                        'percent' => $c->capacity > 0 ? $c->current_occupancy / $c->capacity * 100 : 0.0,
                    ])
                    ->sortByDesc('percent')
                    ->values()
                    ->map(fn ($entry, $i) => $this->withMatchColumn([
                        $i + 1,
                        $entry['center']->name,
                        $entry['center']->barangay?->name ?? '-',
                        $entry['center']->capacity,
                        $entry['center']->current_occupancy,
                        round($entry['percent']) . '%',
                        Household::where('evacuation_center_id', $entry['center']->id)->where('status', 'checked_in')->count(),
                        $entry['center']->statusLabel(),
                    ], $filters, $entry['center']))
                    ->all(),
                'Shelter Ranking',
            ],

            // PHASE 11 - Shelter Demographic Summary Report
            // Aggregated counts by vulnerability category for a specific shelter
            // as requested by professor to see demographic breakdown per shelter
            'shelter_demographic_summary' => [
                ['Shelter', 'Barangay', 'Vulnerability Category', 'Count', 'Percentage', 'Currently Present'],
                $this->filterShelters(
                    EvacuationCenter::with('barangay')->when($center, fn ($q) => $q->whereKey($center->id)),
                    $filters
                )->get()
                    ->map(function ($c) use ($filters) {
                        $members = HouseholdMember::with(['activeClassifications'])
                            ->whereHas('household', fn ($q) => $q->where('evacuation_center_id', $c->id))
                            ->get();

                        $totalMembers = $members->count();

                        // Get all vulnerability classifications
                        $classifications = \App\Models\VulnerableClassification::where('selectable', true)
                            ->orderBy('name')
                            ->get();

                        $summaryRows = [];
                        foreach ($classifications as $classification) {
                            $count = $members->filter(function ($member) use ($classification) {
                                return $member->activeClassifications->contains('id', $classification->id);
                            })->count();

                            $presentCount = $members->filter(function ($member) use ($classification) {
                                return $member->activeClassifications->contains('id', $classification->id) && $member->is_present;
                            })->count();

                            $percentage = $totalMembers > 0 ? round(($count / $totalMembers) * 100, 1) : 0;

                            $summaryRows[] = [
                                $c->name,
                                $c->barangay?->name ?? '-',
                                $classification->name,
                                $count,
                                $percentage . '%',
                                $presentCount,
                            ];
                        }

                        // Add row for members with no vulnerabilities
                        $noVulnerabilityCount = $members->filter(function ($member) {
                            return $member->activeClassifications->isEmpty();
                        })->count();

                        $noVulnerabilityPresent = $members->filter(function ($member) {
                            return $member->activeClassifications->isEmpty() && $member->is_present;
                        })->count();

                        $noVulnerabilityPercentage = $totalMembers > 0 ? round(($noVulnerabilityCount / $totalMembers) * 100, 1) : 0;

                        $summaryRows[] = [
                            $c->name,
                            $c->barangay?->name ?? '-',
                            'No Vulnerability',
                            $noVulnerabilityCount,
                            $noVulnerabilityPercentage . '%',
                            $noVulnerabilityPresent,
                        ];

                        // Add total row
                        $totalPresent = $members->where('is_present', true)->count();
                        $summaryRows[] = [
                            $c->name,
                            $c->barangay?->name ?? '-',
                            'TOTAL',
                            $totalMembers,
                            '100%',
                            $totalPresent,
                        ];

                        return $summaryRows;
                    })->flatten(1)->all(),
                'Shelter Demographic Summary',
            ],
        };
    }
}
