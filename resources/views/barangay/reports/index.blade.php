@extends('layouts.staff')

@section('title', 'Report Generation')
@section('page-title', 'Report Generation')
@section('page-subtitle', 'Generate downloadable reports for your barangay\'s evacuation center.')

@section('content')
<section class="dash-columns">
    <div class="card panel">
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

            <div class="member-grid">
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

            <button type="submit" class="btn-primary">⬇ Generate &amp; Download</button>
        </form>
    </div>

    <div class="dash-side">
        <h2 class="panel-title">Recently Generated</h2>
        <div class="card panel activity-panel">
            @forelse($recent as $r)
                <div class="activity-row">
                    <span class="activity-name">{{ \App\Models\GeneratedReport::typeLabel($r->report_type) }}
                        <small class="text-muted">.{{ $r->format }}</small></span>
                    <time class="activity-time">{{ $r->created_at->diffForHumans() }}</time>
                </div>
            @empty
                <p class="empty-note">Reports you generate will be listed here.</p>
            @endforelse
        </div>
    </div>
</section>
@endsection
