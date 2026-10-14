@extends('layouts.cityadmin')

@section('title', 'Report Generation')
@section('page-title', 'Report Generation')
@section('page-subtitle', 'Generate reports across any shelter or the whole city.')

@section('content')
{{--
    PHASE 3 ITEM 11a -- the CSWDO IDP Monitoring Form.

    Its OWN panel and its OWN route, not another option in the dropdown below:
    that dropdown validates against ReportController::TYPES and offers a
    PDF/xlsx choice, and this form is a fixed two-cross-tab layout meant to be
    printed and signed.

    Unlike the barangay side, the shelter select may be left blank. That prints
    every shelter accumulated with the header reading "Accumulated Shelters" --
    a city-level roll-up with no equivalent on the paper form, which is why only
    City Admin has it.
--}}
<section class="card panel mb-4">
    <h2 class="panel-title">CSWDO IDP Monitoring Form</h2>
    <p class="mb-3 text-sm text-ink-muted">
        The official CSWDO monitoring sheet, filled in from the families checked in
        right now. Pick one shelter for the sheet CSWDO files per centre, or leave it
        on all shelters for a city-wide accumulated sheet. PDF only, ready to print
        and sign.
    </p>

    <form method="POST" action="{{ route('city.reports.idp') }}">
        @csrf
        <div class="field">
            <label for="idp-center">Shelter</label>
            <select id="idp-center" name="center">
                <option value="">All shelters (Accumulated Shelters)</option>
                @foreach($shelters ?? [] as $s)<option value="{{ $s->id }}" @selected(old('center') == $s->id)>{{ $s->name }}</option>@endforeach
            </select>
        </div>

        <div class="member-grid">
            <div class="field">
                <label for="idp-disaster">Name of disaster</label>
                <input type="text" id="idp-disaster" name="disaster_name" maxlength="150"
                       placeholder="e.g. Typhoon Kristine"
                       value="{{ old('disaster_name') }}" required>
            </div>
            <div class="field">
                <label for="idp-date">Date of disaster</label>
                <input type="date" id="idp-date" name="disaster_date"
                       max="{{ now()->toDateString() }}"
                       value="{{ old('disaster_date') }}" required>
            </div>
        </div>

        <div class="member-grid">
            <div class="field">
                <label for="idp-families">Number of affected families</label>
                <input type="number" id="idp-families" name="affected_families" min="0"
                       value="{{ old('affected_families', $idpCounts['families']) }}">
                <p class="mt-1 text-xs text-ink-muted">
                    Pre-filled with the {{ $idpCounts['families'] }} checked in city-wide
                    right now. The form asks for the city-wide affected figure, so
                    overwrite this with the CDRRMO number if you have it. Clear it to
                    print a blank line.
                </p>
            </div>
            <div class="field">
                <label for="idp-persons">Number of affected persons</label>
                <input type="number" id="idp-persons" name="affected_persons" min="0"
                       value="{{ old('affected_persons', $idpCounts['persons']) }}">
                <p class="mt-1 text-xs text-ink-muted">
                    Pre-filled with the {{ $idpCounts['persons'] }} present city-wide right
                    now. Same rule as families.
                </p>
            </div>
        </div>

        <p class="mb-3 text-xs text-ink-muted">
            Prepared by and Noted by print as blank signature lines. Noted by carries
            the OIC CSWDO designation.
        </p>

        {{-- DROP D. Preview streams the same $pdf object the download returns,
             so the two cannot disagree. Generate stays btn-primary and stays
             LAST, so it remains the form's default submit -- pressing Enter in
             a text field still downloads rather than previews.

             formtarget="_blank" is on the PREVIEW button only, not on the
             <form>. One attribute, no JS, no bundle rebuild, and the two
             buttons keep different targets from the same form: preview opens a
             new tab, Generate posts in place so the download and any
             validation errors land on the page the operator is looking at. --}}
        <div class="flex flex-col gap-2 sm:flex-row">
            <button type="submit" name="action" value="preview" formtarget="_blank" class="btn-secondary w-full min-h-[44px] sm:w-auto">Preview IDP Form</button>
            <button type="submit" class="btn-primary w-full min-h-[44px] sm:w-auto">&darr; Generate IDP Form (PDF)</button>
        </div>
    </form>
</section>

<section class="grid grid-cols-1 items-start gap-4 lg:grid-cols-3">
    <div class="card panel lg:col-span-2">
        <h2 class="panel-title">Generate a Report</h2>
        <form method="POST" action="{{ route('city.reports.generate') }}" data-report-generator data-report-definitions="{{ json_encode($reportDefinitions) }}">
            @csrf
            <div class="field">
                <label for="rep-type">Report type</label>
                <select id="rep-type" name="report_type" required>
                    <option value="household_registry" @selected(old('report_type', 'household_registry') === 'household_registry')>Household Registry</option>
                    <option value="attendance" @selected(old('report_type', 'household_registry') === 'attendance')>Attendance / Headcount</option>
                    <option value="relief" @selected(old('report_type', 'household_registry') === 'relief')>Relief Distribution</option>
                    <option value="relief_received" @selected(old('report_type', 'household_registry') === 'relief_received')>Relief Stock Received</option>
                    <option value="relief_inventory" @selected(old('report_type') === 'relief_inventory')>Relief Inventory Status (current)</option>
                    <option value="shelter_transfers" @selected(old('report_type') === 'shelter_transfers')>Shelter Transfer History</option>
                    <option value="vulnerable" @selected(old('report_type', 'household_registry') === 'vulnerable')>Vulnerable Population</option>
                    <option value="occupancy" @selected(old('report_type', 'household_registry') === 'occupancy')>Shelter Occupancy Summary</option>
                    {{-- Item 11b. Demographics is every member, not only the
                         tagged ones; Shelter Ranking is City Admin only. --}}
                    <option value="demographics" @selected(old('report_type', 'household_registry') === 'demographics')>Evacuee Demographics</option>
                    <option value="shelter_ranking" @selected(old('report_type', 'household_registry') === 'shelter_ranking')>Shelter Ranking (most to least full)</option>
                    {{-- Phase 11 - Shelter Demographic Summary --}}
                    <option value="shelter_demographic_summary" @selected(old('report_type', 'household_registry') === 'shelter_demographic_summary')>Shelter Demographic Summary</option>
                </select>
            </div>
            <p class="mb-3 text-sm text-ink-muted" data-report-description aria-live="polite"></p>
            <div class="field" data-report-center>
                <label for="rep-center">Shelter <small>(optional)</small></label>
                <select id="rep-center" name="center">
                    <option value="">All shelters (city-wide)</option>
                    @foreach($shelters ?? [] as $s)
                        <option value="{{ $s->id }}" @selected(old('center') == $s->id && old('center') !== null)>{{ $s->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field" data-report-stock-filter hidden>
                <label for="rep-stock-state">Stock level <small>(optional)</small></label>
                <select id="rep-stock-state" name="stock_state">
                    <option value="">All tracked stock levels</option>
                    <option value="out" @selected(old('stock_state') === 'out')>Out of stock (0 on hand)</option>
                    <option value="low" @selected(old('stock_state') === 'low')>Low stock (positive, at/below reorder level)</option>
                    <option value="adequate" @selected(old('stock_state') === 'adequate')>Adequate stock</option>
                </select>
            </div>
            <div class="field" data-report-transfer-filter hidden>
                <label for="rep-transfer-status">Transfer status <small>(optional)</small></label>
                <select id="rep-transfer-status" name="transfer_status">
                    <option value="">All transfer statuses</option>
                    <option value="pending" @selected(old('transfer_status') === 'pending')>Pending confirmation</option>
                    <option value="approved" @selected(old('transfer_status') === 'approved')>Approved</option>
                    <option value="in_transit" @selected(old('transfer_status') === 'in_transit')>In transit</option>
                    <option value="completed" @selected(old('transfer_status') === 'completed')>Completed</option>
                    <option value="refused" @selected(old('transfer_status') === 'refused')>Refused</option>
                    <option value="cancelled" @selected(old('transfer_status') === 'cancelled')>Cancelled</option>
                </select>
            </div>
            <div data-report-dates>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div class="field">
                        <label for="rep-from">Date from <small>(optional)</small></label>
                        <input type="date" id="rep-from" name="date_from" value="{{ old('date_from') }}" max="{{ now()->toDateString() }}">
                    </div>
                    <div class="field">
                        <label for="rep-to">Date to <small>(optional)</small></label>
                        <input type="date" id="rep-to" name="date_to" value="{{ old('date_to') }}" max="{{ now()->toDateString() }}">
                    </div>
                </div>
            </div>
            <p class="mb-3 text-xs text-ink-muted" data-report-date-note aria-live="polite"></p>
            <details data-report-demographics class="mb-4 rounded-lg border border-border p-3">
                <summary class="cursor-pointer font-medium" data-report-demographics-summary>Demographic filters (optional)</summary>
                <p class="mb-2 mt-2 text-xs text-ink-muted" data-report-demographics-note></p>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <div class="field">
                        <label for="rep-sex">Sex</label>
                        <select id="rep-sex" name="sex">
                            <option value="">Any</option>
                            @foreach($filterOptions['sexes'] as $value => $label)
                                <option value="{{ $value }}" @selected(old('sex') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="rep-age-tier">Age group</label>
                        <select id="rep-age-tier" name="age_tier">
                            <option value="">Any</option>
                            @foreach($filterOptions['tiers'] as $value => $label)
                                <option value="{{ $value }}" @selected(old('age_tier') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="rep-category">Vulnerable category</label>
                        <select id="rep-category" name="category">
                            <option value="">Any</option>
                            @foreach($filterOptions['categories'] as $value => $label)
                                <option value="{{ $value }}" @selected(old('category') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </details>
            <div class="field">
                <label>File format</label>
                <div class="radio-list">
                    <label class="radio-row"><input type="radio" name="format" value="pdf" @checked(old('format', 'pdf') === 'pdf')> PDF (printable)</label>
                    <label class="radio-row"><input type="radio" name="format" value="xlsx" @checked(old('format') === 'xlsx')> Excel (.xlsx)</label>
                </div>
                <p class="mt-1 text-xs text-ink-muted">
                    Preview opens the PDF in a new tab and downloads nothing.
                    Excel files cannot be previewed and will always download.
                </p>
            </div>
            <div class="flex flex-col gap-2 sm:flex-row">
                <button type="submit" name="action" value="preview" formtarget="_blank" class="btn-secondary w-full min-h-[44px] sm:w-auto">Preview</button>
                <button type="submit" class="btn-primary w-full min-h-[44px] sm:w-auto">&darr; Generate &amp; Download</button>
            </div>
        </form>
    </div>

    <div class="flex flex-col">
        <h2 class="panel-title">Recently Generated</h2>
        <div class="card panel activity-panel">
            @forelse($recent ?? [] as $r)
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-border p-3 text-sm last:border-b-0">
                    <span class="min-w-0 flex-1 font-medium">{{ $r->type_label }} <small class="text-ink-muted">.{{ $r->format }}</small></span>
                    <time class="text-ink-muted">{{ $r->created_at->diffForHumans() }}</time>
                </div>
            @empty
                <p class="empty-note">Reports you generate will be listed here.</p>
            @endforelse
        </div>
    </div>
</section>
@endsection
