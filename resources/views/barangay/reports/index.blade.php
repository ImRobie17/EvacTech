@extends('layouts.staff')

@section('title', 'Report Generation')
@section('page-title', 'Report Generation')
@section('page-subtitle', 'Generate downloadable reports for your barangay\'s evacuation center.')

{{--
    Converted to Tailwind (roadmap item 2).

    The barangay views take a NARROWER conversion than the public ones: layout,
    spacing and responsive behaviour move to utilities, but shared component
    classes (.card, .panel, .btn-*, .badge*, .field, .data-table, .kpi-*,
    .alert*, .empty-note, .modal*) stay as classes. Those are defined in
    staff.css and are still used verbatim by the City Admin and Super Admin
    views, which Chat C has not converted yet -- rewriting them here would mean
    either breaking those pages or converting them out of turn.

    Chat C should follow the same rule so the two halves match.
--}}

@section('content')
{{-- The old .dash-columns gave both panels equal width at every size. The form
     is the task and the history is reference, so from 1024px the form takes two
     thirds. Below that they stack, form first. --}}
<section class="grid grid-cols-1 gap-4 lg:grid-cols-3">
    <div class="card panel lg:col-span-2">
        <h2 class="panel-title">Generate a Report</h2>
        <form method="POST" action="{{ route('barangay.reports.generate') }}">
            @csrf
            <div class="field">
                <label for="rep-type">Report type</label>
                <select id="rep-type" name="report_type" required>
                    <option value="household_registry">Household Registry</option>
                    <option value="attendance">Attendance / Headcount</option>
                    <option value="relief">Relief Distribution</option>
                    <option value="vulnerable">Vulnerable Population</option>
                    <option value="occupancy">Shelter Occupancy Summary</option>
                </select>
            </div>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div class="field">
                    <label for="rep-from">Date from <small>(optional)</small></label>
                    <input type="date" id="rep-from" name="date_from" max="{{ now()->toDateString() }}">
                </div>
                <div class="field">
                    <label for="rep-to">Date to <small>(optional)</small></label>
                    <input type="date" id="rep-to" name="date_to" max="{{ now()->toDateString() }}">
                </div>
            </div>

            <div class="field">
                <label>File format</label>
                <div class="radio-list">
                    <label class="radio-row"><input type="radio" name="format" value="pdf" checked> PDF (printable)</label>
                    <label class="radio-row"><input type="radio" name="format" value="xlsx"> Excel (.xlsx)</label>
                </div>
            </div>

            <button type="submit" class="btn-primary w-full sm:w-auto">&darr; Generate &amp; Download</button>
        </form>
    </div>

    <div>
        <h2 class="panel-title">Recently Generated</h2>
        <div class="card panel activity-panel">
            @forelse($recent as $r)
                {{-- Wraps instead of truncating below 640px: the type and the
                     format are both needed to tell two downloads apart. --}}
                <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1 border-b border-surface-alt py-2 last:border-b-0">
                    <span>{{ \App\Models\GeneratedReport::typeLabel($r->report_type) }}
                        <small class="text-ink-muted">.{{ $r->format }}</small></span>
                    <time class="text-sm text-ink-muted">{{ $r->created_at->diffForHumans() }}</time>
                </div>
            @empty
                <p class="empty-note">Reports you generate will be listed here.</p>
            @endforelse
        </div>
    </div>
</section>
@endsection
