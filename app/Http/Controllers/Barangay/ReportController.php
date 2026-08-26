<?php

namespace App\Http\Controllers\Barangay;

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

class ReportController extends BarangayController
{
    use FiltersReports;
    use RendersIdpForm;

    /**
     * PHASE 3 ITEM 11b added 'demographics' -- every member at this shelter,
     * tagged or not, so the three demographic filters have something to filter
     * that is not already restricted to vulnerable people.
     *
     * 'shelter_ranking' is deliberately NOT here. Barangay staff operate one
     * shelter at a time, and a ranking of one row is not a report. It is City
     * Admin only.
     */
    public const TYPES = [
        'household_registry', 'attendance', 'relief', 'vulnerable',
        'occupancy', 'demographics',
    ];

    public function index()
    {
        $center = $this->center();

        $recent = GeneratedReport::with('generatedBy')
            ->when($center, fn ($q) => $q->where('evacuation_center_id', $center->id))
            ->where('generated_by', auth()->id())
            ->latest()
            ->take(8)
            ->get();

        // Pre-fill for the two "Number of Affected ..." inputs on the IDP form
        // panel. An in-shelter headcount, labelled as such in the view -- see
        // IdpForm::headcount() for why it is not the figure the form asks for.
        $idpCounts = IdpForm::headcount($center);

        // Item 11b. Prepared here rather than read from the class in Blade --
        // controllers prepare, views render.
        $filterOptions = $this->reportFilterOptions();

        return view('barangay.reports.index', compact('center', 'recent', 'idpCounts', 'filterOptions'));
    }

    /**
     * The CSWDO IDP Monitoring Form (Phase 3 item 11a).
     *
     * A SEPARATE action, deliberately not another entry in self::TYPES. TYPES is
     * the whitelist for the generic dropdown, which also offers an xlsx radio
     * and routes into buildDataset(). This form is a fixed layout with two
     * cross-tabs, so an xlsx of it would be a broken artifact and a generic
     * branch would need guards in three places. PDF only, its own route, its own
     * view, and the working report types are left untouched.
     *
     * Every figure comes from IdpForm, shared with the City Admin side.
     */
    public function idp(Request $request)
    {
        $data = $request->validate([
            'disaster_name' => ['required', 'string', 'max:150'],
            'disaster_date' => ['required', 'date', 'before_or_equal:today'],
            'affected_families' => ['nullable', 'integer', 'min:0', 'max:9999999'],
            'affected_persons' => ['nullable', 'integer', 'min:0', 'max:9999999'],
        ], [
            'disaster_name.required' => 'Enter the name of the disaster, e.g. Typhoon Kristine.',
            'disaster_date.required' => 'Enter the date the disaster occurred.',
        ]);

        $center = $this->centerOrFail();
        $form = IdpForm::forCenter($center);

        $record = GeneratedReport::create([
            'report_type' => IdpForm::TYPE,
            'format' => 'pdf',
            'generated_by' => auth()->id(),
            'evacuation_center_id' => $center->id,
        ]);

        AuditLogger::log('created', $record,
            "Generated IDP Monitoring Form for {$center->name} (disaster: {$data['disaster_name']})");

        return $this->renderIdpPdf($data, $form);
    }

    public function generate(Request $request)
    {
        $data = $request->validate(array_merge([
            'report_type' => ['required', 'in:' . implode(',', self::TYPES)],
            'format' => ['required', 'in:pdf,xlsx'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ], $this->reportFilterRules()));

        $center = $this->centerOrFail();
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
            'evacuation_center_id' => $center->id,
        ]);

        $filterLabels = $this->reportFilterLabels($filters);

        // The filters are NOT persisted on generated_reports -- that would be a
        // migration for a column nothing reads back. They are recorded here,
        // where an auditor can already see who generated what and when.
        $note = $filterLabels === [] ? '' : ('; filters: ' . implode(' | ', $filterLabels));
        AuditLogger::log('created', $record, "Generated {$title} report ({$data['format']}{$note})");

        $filename = str_replace(' ', '_', strtolower($title))
            . $this->reportFilterSlug($filters)
            . '_' . now()->format('Ymd_His');

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
            'filters' => $filterLabels,
            'filterNote' => $this->reportFilterNote($data['report_type'], $filters),
            'generatedBy' => auth()->user()->name,
        ])->setPaper('a4', 'landscape');

        return $pdf->download($filename . '.pdf');
    }

    /**
     * $filters is [] for an unfiltered report, in which case every query and
     * every heading row below behaves exactly as it did before item 11b.
     *
     * @return array{0: string[], 1: array[], 2: string}
     */
    private function buildDataset(string $type, ?string $from, ?string $to, $center, array $filters = []): array
    {
        $range = fn ($q, $col = 'created_at') => $q
            ->when($from, fn ($q) => $q->whereDate($col, '>=', $from))
            ->when($to, fn ($q) => $q->whereDate($col, '<=', $to));

        return match ($type) {
            'household_registry' => [
                $this->withMatchColumn(
                    ['Household ID', 'Household Head', 'Family Size', 'Origin Barangay', 'Address', 'Status', 'Shelter', 'Registered On'],
                    $filters, 'Matching Members'
                ),
                // RESCOPED from origin_barangay_id to the shelter being operated:
                // staff are assigned to shelters, not barangays. Households not yet
                // placed in a shelter are included so the registry stays complete.
                $range($this->filterHouseholds(
                    Household::with(['headMember', 'evacuationCenter', 'originBarangay'])
                        ->where(fn ($q) => $q
                            ->where('evacuation_center_id', $center->id)
                            ->orWhereNull('evacuation_center_id')),
                    $filters
                ))
                    ->get()
                    ->map(fn ($h) => $this->withMatchColumn([
                        $h->household_code,
                        $h->headMember?->full_name ?? '-',
                        $h->number_of_members,
                        $h->originBarangay?->name ?? '-',
                        $h->origin_address,
                        ucfirst(str_replace('_', ' ', $h->status)),
                        $h->evacuationCenter?->name ?? '-',
                        $h->created_at->format('M d, Y'),
                    ], $filters, $h))->all(),
                'Household Registry',
            ],
            'attendance' => [
                $this->withMatchColumn(
                    ['Household ID', 'Household Head', 'Members Present', 'Status', 'Checked In', 'Checked Out'],
                    $filters, 'Matching Members'
                ),
                $range($this->filterHouseholds(
                    Household::with('headMember')->where('evacuation_center_id', $center->id),
                    $filters
                ), 'checked_in_at')
                    ->whereNotNull('checked_in_at')
                    ->get()
                    ->map(fn ($h) => $this->withMatchColumn([
                        $h->household_code,
                        $h->headMember?->full_name ?? '-',
                        $h->members_present,
                        ucfirst(str_replace('_', ' ', $h->status)),
                        $h->checked_in_at?->format('M d, Y h:i A'),
                        $h->checked_out_at?->format('M d, Y h:i A') ?? '-',
                    ], $filters, $h))->all(),
                'Attendance Headcount',
            ],
            'relief' => [
                ['Date', 'Household Head', 'Relief Good', 'Quantity', 'Distributed By', 'Remarks'],
                $range($this->filterRelief(
                    ReliefTransaction::with(['household.headMember', 'reliefGood', 'recordedBy'])
                        ->where('evacuation_center_id', $center->id)
                        ->where('type', 'distributed'),
                    $filters
                ), 'transaction_date')
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
                ['Name', 'Age', 'Age Group', 'Sex', 'Household', 'Categories', 'Currently Present'],
                $range($this->filterMembers(
                    HouseholdMember::with(['household', 'activeClassifications'])
                        ->whereHas('household', fn ($q) => $q->where('evacuation_center_id', $center->id))
                        ->whereHas('activeClassifications'),
                    $filters
                ), 'household_members.created_at')
                    ->get()
                    ->map(fn ($m) => [
                        $m->full_name,
                        $m->age ?? '-',
                        $m->ageTierLabel(),
                        ucfirst((string) $m->sex),
                        $m->household?->household_code ?? '-',
                        $m->activeClassifications->pluck('name')->implode(', '),
                        $m->is_present ? 'Yes' : 'No',
                    ])->all(),
                'Vulnerable Population',
            ],

            // ITEM 11b. Every member at this shelter, tagged or not. Vulnerable
            // Population answers "who needs special handling"; this answers
            // "who is here", which is the question the three filters are for.
            'demographics' => [
                ['Name', 'Age', 'Age Group', 'Sex', 'Household', 'Categories', 'Currently Present'],
                $range($this->filterMembers(
                    HouseholdMember::with(['household', 'activeClassifications'])
                        ->whereHas('household', fn ($q) => $q->where('evacuation_center_id', $center->id)),
                    $filters
                ), 'household_members.created_at')
                    ->get()
                    ->map(fn ($m) => [
                        $m->full_name,
                        $m->age ?? '-',
                        $m->ageTierLabel(),
                        ucfirst((string) $m->sex),
                        $m->household?->household_code ?? '-',
                        $m->activeClassifications->pluck('name')->implode(', ') ?: '-',
                        $m->is_present ? 'Yes' : 'No',
                    ])->all(),
                'Evacuee Demographics',
            ],

            // Occupancy is a live snapshot, so the date range does not apply --
            // it never did. Built through filterShelters() rather than as a
            // hand-made single row so that a filter can legitimately return NO
            // rows: "this shelter currently holds nobody matching" is a real and
            // useful answer, and a hardcoded row could never give it.
            'occupancy' => [
                $this->withMatchColumn(
                    ['Shelter', 'Capacity', 'Current Occupancy', 'Occupancy %', 'Households Checked-in', 'Status'],
                    $filters, 'Matching Members'
                ),
                $this->filterShelters(EvacuationCenter::whereKey($center->id), $filters)
                    ->get()
                    ->map(fn ($c) => $this->withMatchColumn([
                        $c->name,
                        $c->capacity,
                        $c->current_occupancy,
                        ($c->capacity > 0 ? round($c->current_occupancy / $c->capacity * 100) : 0) . '%',
                        Household::where('evacuation_center_id', $c->id)->where('status', 'checked_in')->count(),
                        $c->statusLabel(),
                    ], $filters, $c))->all(),
                'Shelter Occupancy Summary',
            ],
        };
    }
}
