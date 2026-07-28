@extends('layouts.cityadmin')

@section('title', 'Report Generation')
@section('page-title', 'Report Generation')
@section('page-subtitle', 'Generate reports across any shelter or the whole city.')

@section('content')
{{-- The form gets two thirds and the history one third from 1024px. The old
     .dash-columns gave both equal width at every size, which left a form of
     five controls sharing the screen with a short list. --}}
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
                    <span class="min-w-0 flex-1 font-medium">{{ \App\Models\GeneratedReport::typeLabel($r->report_type) }} <small class="text-ink-muted">.{{ $r->format }}</small></span>
                    <time class="text-ink-muted">{{ $r->created_at->diffForHumans() }}</time>
                </div>
            @empty
                <p class="empty-note">Reports you generate will be listed here.</p>
            @endforelse
        </div>
    </div>
</section>
@endsection
