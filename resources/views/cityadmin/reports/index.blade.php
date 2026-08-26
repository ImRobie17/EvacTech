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

        <button type="submit" class="btn-primary">&darr; Generate IDP Form (PDF)</button>
    </form>
</section>

<section class="grid grid-cols-1 items-start gap-4 lg:grid-cols-3">
    <div class="card panel lg:col-span-2">
        <h2 class="panel-title">Generate a Report</h2>
        <form method="POST" action="{{ route('city.reports.generate') }}">
            @csrf
            <div class="field">
                <label for="rep-type">Report type</label>
                <select id="rep-type" name="report_type" required>
                    <option value="household_registry">Household Registry</option>
                    <option value="attendance">Attendance / Headcount</option>
                    <option value="relief">Relief Distribution</option>
                    <option value="vulnerable">Vulnerable Population</option>
                    <option value="occupancy">Shelter Occupancy Summary</option>
                    {{-- Item 11b. Demographics is every member, not only the
                         tagged ones; Shelter Ranking is City Admin only. --}}
                    <option value="demographics">Evacuee Demographics</option>
                    <option value="shelter_ranking">Shelter Ranking (most to least full)</option>
                </select>
            </div>
            <div class="field">
                <label for="rep-center">Shelter <small>(leave blank for all shelters)</small></label>
                <select id="rep-center" name="center">
                    <option value="">All shelters (city-wide)</option>
                    @foreach($shelters ?? [] as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach
                </select>
            </div>
            <div class="member-grid">
                <div class="field"><label for="rep-from">Date from <small>(optional)</small></label><input type="date" id="rep-from" name="date_from" max="{{ now()->toDateString() }}"></div>
                <div class="field"><label for="rep-to">Date to <small>(optional)</small></label><input type="date" id="rep-to" name="date_to" max="{{ now()->toDateString() }}"></div>
            </div>

            {{--
                PHASE 3 ITEM 11b -- the three demographic filters.

                They apply to EVERY report type, not just the member-level ones.
                All three are properties of a member, so on a household or
                shelter report they select which rows appear -- households or
                shelters containing at least one matching member -- rather than
                recomputing the figures inside those rows. The generated PDF says
                so in its header, and a Matching Members column is added so the
                distinction is visible in the table itself.

                Leaving all three on "Any" produces exactly the report you got
                before this feature existed.
            --}}
            <div class="field">
                <label>Demographic filters <small>(optional)</small></label>
                <p class="mt-1 mb-2 text-xs text-ink-muted">
                    Narrow the report to a sex, an age group, a vulnerable category, or
                    any combination. On household and shelter reports these choose which
                    rows appear; totals stay whole-household and whole-shelter figures,
                    and a Matching Members column is added.
                </p>
            </div>

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

            <div class="field">
                <label>File format</label>
                <div class="radio-list">
                    <label class="radio-row"><input type="radio" name="format" value="pdf" checked> PDF (printable)</label>
                    <label class="radio-row"><input type="radio" name="format" value="xlsx"> Excel (.xlsx)</label>
                </div>
            </div>
            <button type="submit" class="btn-primary">&darr; Generate &amp; Download</button>
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
