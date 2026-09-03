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
     *
     * PHASE 11 added 'shelter_demographic_summary' -- aggregated counts by
     * vulnerability category for a specific shelter, as requested by professor.
     */
    public const TYPES = [
        'household_registry', 'attendance', 'relief', 'vulnerable',
        'occupancy', 'demographics', 'shelter_demographic_summary',
        'relief_received',
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
            // DROP D. Preview is a second SUBMIT BUTTON, not a third format.
            // Nothing else may be posted here, so the whitelist is one value.
            'action' => ['nullable', 'in:preview'],
            'disaster_name' => ['required', 'string', 'max:150'],
            'disaster_date' => ['required', 'date', 'before_or_equal:today'],
            'affected_families' => ['nullable', 'integer', 'min:0', 'max:9999999'],
            'affected_persons' => ['nullable', 'integer', 'min:0', 'max:9999999'],
        ], [
            'disaster_name.required' => 'Enter the name of the disaster, e.g. Typhoon Kristine.',
            'disaster_date.required' => 'Enter the date the disaster occurred.',
        ]);

        $preview = ($data['action'] ?? null) === 'preview';

        $center = $this->centerOrFail();
        $form = IdpForm::forCenter($center);

        // DROP D. A preview IS logged. It renders a full shelter roll-up, and a
        // read that leaves no trace is worse than a Recently Generated list
        // that fills up faster. The format stays 'pdf' -- that column records
        // the artifact, not how it was delivered -- and the audit description
        // is the only thing that distinguishes the two.
        $record = GeneratedReport::create([
            'report_type' => IdpForm::TYPE,
            'format' => 'pdf',
            'generated_by' => auth()->id(),
            'evacuation_center_id' => $center->id,
        ]);

        $verb = $preview ? 'Previewed' : 'Generated';
        AuditLogger::log('created', $record,
            "{$verb} IDP Monitoring Form for {$center->name} (disaster: {$data['disaster_name']})");

        return $this->renderIdpPdf($data, $form, $preview);
    }

    public function generate(Request $request)
    {
        $data = $request->validate(array_merge([
            'report_type' => ['required', 'in:' . implode(',', self::TYPES)],
            'format' => ['required', 'in:pdf,xlsx'],
            // DROP D. Deliberately NOT a third value on the format radio.
            // Preview is orthogonal to format: a 'preview' radio would create
            // the combination preview+xlsx, which is invalid and would need
            // guarding anyway, and it would write the string 'preview' into
            // generated_reports.format where a real format belongs. A separate
            // submit button leaves format clean.
            'action' => ['nullable', 'in:preview'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ], $this->reportFilterRules()));

        $preview = ($data['action'] ?? null) === 'preview';

        // A spreadsheet cannot be rendered inline by a browser. Refuse the
        // combination out loud instead of quietly downloading -- a control that
        // silently does something other than what it says is the thing this
        // drop exists to avoid. withInput() keeps the operator's filters and
        // dates so they only have to change the one radio.
        if ($preview && $data['format'] === 'xlsx') {
            return back()->withInput()->withErrors([
                'format' => 'Excel files cannot be previewed. Choose PDF to preview, or press Generate to download the spreadsheet.',
            ]);
        }

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
        $verb = $preview ? 'Previewed' : 'Generated';
        AuditLogger::log('created', $record, "{$verb} {$title} report ({$data['format']}{$note})");

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

        return $this->pdfResponse($pdf, $filename . '.pdf', $preview);
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

            /* DROP B2 -- stock RECEIVED, the counterpart to the distribution
               report above.

               THE TRAP, AND IT IS THE SAME ONE DROP 0 FIXED. The three
               demographic filters apply to EVERY type on this form (gotcha 39).
               A received transaction has household_id = NULL, so running this
               arm through filterRelief() -- which does
               whereHas('household.members', ...) -- would return ZERO ROWS the
               moment any filter is set, silently, while the PDF header
               cheerfully printed "Filtered by: Category: PWD". A report that
               quietly answers a different question from the one printed on it is
               worse than one that crashes.

               So the demographic filters are NOT applied here at all, and
               FiltersReports::reportFilterNote() carries a case saying so in
               plain words. The date range still applies: that is a property of
               the receipt, not of a household.

               allocated_in rows are INCLUDED by decision. Stock from an approved
               city restock is real stock-in, and since Drop B1 it can carry a
               donor too, because a donation does not always reach a shelter
               directly. Excluding them would make this report disagree with the
               inventory it exists to explain. */
            'relief_received' => [
                ['Date', 'Item', 'Quantity', 'Donor Type', 'Donor Name', 'Value (PHP)', 'Received By', 'Remarks'],
                $this->reliefReceivedRows(
                    $range(
                        ReliefTransaction::with(['reliefGood', 'recordedBy'])
                            ->where('evacuation_center_id', $center->id)
                            ->whereIn('type', ReliefTransaction::STOCK_IN_TYPES),
                        'transaction_date'
                    )->latest('transaction_date')->get()
                ),
                'Relief Stock Received',
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
            // DROP D added Household Head, Contact Number and Head Contact.
            // This report is already ONE ROW PER PERSON; the gap was never
            // person-vs-household, it was that the person rows do not say who
            // to call. Two separate number columns rather than one with a
            // fallback: the heading then says whose number it is, and a blank
            // Contact Number beside a populated Head Contact reads correctly
            // without a marker. contact_number lives on household_members, so
            // the head's is one eager-load away via household.headMember.
            //
            // headMember(), NOT currentHead(). The acting head is a shelter
            // operations stand-in and v10 keeps it out of reports entirely.
            'vulnerable' => [
                ['Name', 'Age', 'Age Group', 'Sex', 'Household', 'Categories',
                    'Household Head', 'Contact Number', 'Head Contact', 'Currently Present'],
                $range($this->filterMembers(
                    HouseholdMember::with(['household.headMember', 'activeClassifications'])
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
                        $m->household?->headMember?->full_name ?? '-',
                        $m->contact_number ?: '-',
                        $m->household?->headMember?->contact_number ?: '-',
                        $m->is_present ? 'Yes' : 'No',
                    ])->all(),
                'Vulnerable Population',
            ],

            // ITEM 11b. Every member at this shelter, tagged or not. Vulnerable
            // Population answers "who needs special handling"; this answers
            // "who is here", which is the question the three filters are for.
            'demographics' => [
                // DROP D. Same three columns as 'vulnerable', in the same
                // order. The two reports are read side by side and a column
                // that moves between them is a column that gets misread.
                ['Name', 'Age', 'Age Group', 'Sex', 'Household', 'Categories',
                    'Household Head', 'Contact Number', 'Head Contact', 'Currently Present'],
                $range($this->filterMembers(
                    HouseholdMember::with(['household.headMember', 'activeClassifications'])
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
                        $m->household?->headMember?->full_name ?? '-',
                        $m->contact_number ?: '-',
                        $m->household?->headMember?->contact_number ?: '-',
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

            // PHASE 11 - Shelter Demographic Summary Report
            // Aggregated counts by vulnerability category for a specific shelter
            // as requested by professor to see demographic breakdown per shelter
            'shelter_demographic_summary' => [
                ['Vulnerability Category', 'Count', 'Percentage', 'Currently Present'],
                $this->filterShelters(EvacuationCenter::whereKey($center->id), $filters)
                    ->get()
                    ->map(function ($c) use ($filters) {
                        // FIX. As shipped, this closure built $memberQuery,
                        // applied the filters to it, and then never executed it.
                        // Every branch below reads $members and $totalMembers,
                        // neither of which was ever assigned, so the report
                        // fataled with "Call to a member function filter() on
                        // null" on every single run. The query is now run.
                        //
                        // THE FILTER SUBSET IS DELIBERATE. sex and age_tier
                        // decide WHO IS COUNTED. category decides WHICH ROW IS
                        // PRINTED, further down. Passing category in here as
                        // well would narrow the population to that one category
                        // and every percentage would come out as 100%.
                        //
                        // Routed through filterMembers() rather than a
                        // hand-written where(): applyMemberFilters() is the one
                        // definition of these two filters, it qualifies
                        // household_members.sex so the clause survives inside a
                        // sub-query, and it already encodes that sqlCase() is
                        // safe in a WHERE. A fourth hand-written copy of a rule
                        // that already exists in the trait is exactly how these
                        // things drift.
                        $members = $this->filterMembers(
                            HouseholdMember::with(['activeClassifications'])
                                ->whereHas('household', fn ($q) => $q->where('evacuation_center_id', $c->id)),
                            array_intersect_key($filters, array_flip(['sex', 'age_tier']))
                        )->get();

                        $totalMembers = $members->count();

                        // Fetched once. The else branch below re-fetched an
                        // identical collection into this same variable.
                        $classifications = \App\Models\VulnerableClassification::where('is_selectable', true)
                            ->orderBy('name')
                            ->get();

                        $summaryRows = [];

                        // If category filter is set, only show that specific category
                        if (! empty($filters['category'])) {
                            $classification = \App\Models\VulnerableClassification::where('code', $filters['category'])
                                ->where('is_selectable', true)
                                ->first();

                            if ($classification) {
                                $count = $members->filter(function ($member) use ($classification) {
                                    return $member->activeClassifications->contains('id', $classification->id);
                                })->count();

                                $presentCount = $members->filter(function ($member) use ($classification) {
                                    return $member->activeClassifications->contains('id', $classification->id) && $member->is_present;
                                })->count();

                                $percentage = $totalMembers > 0 ? round(($count / $totalMembers) * 100, 1) : 0;

                                $summaryRows[] = [
                                    $classification->name,
                                    $count,
                                    $percentage . '%',
                                    $presentCount,
                                ];
                            }
                        } else {
                            // No category filter: every classification gets a row.
                            foreach ($classifications as $classification) {
                                $count = $members->filter(function ($member) use ($classification) {
                                    return $member->activeClassifications->contains('id', $classification->id);
                                })->count();

                                $presentCount = $members->filter(function ($member) use ($classification) {
                                    return $member->activeClassifications->contains('id', $classification->id) && $member->is_present;
                                })->count();

                                $percentage = $totalMembers > 0 ? round(($count / $totalMembers) * 100, 1) : 0;

                                $summaryRows[] = [
                                    $classification->name,
                                    $count,
                                    $percentage . '%',
                                    $presentCount,
                                ];
                            }

                            // Add row for members with no vulnerabilities (only when no category filter)
                            $noVulnerabilityCount = $members->filter(function ($member) {
                                return $member->activeClassifications->isEmpty();
                            })->count();

                            $noVulnerabilityPresent = $members->filter(function ($member) {
                                return $member->activeClassifications->isEmpty() && $member->is_present;
                            })->count();

                            $noVulnerabilityPercentage = $totalMembers > 0 ? round(($noVulnerabilityCount / $totalMembers) * 100, 1) : 0;

                            $summaryRows[] = [
                                'No Vulnerability',
                                $noVulnerabilityCount,
                                $noVulnerabilityPercentage . '%',
                                $noVulnerabilityPresent,
                            ];
                        }

                        // Add total row
                        $totalPresent = $members->where('is_present', true)->count();
                        $summaryRows[] = [
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
