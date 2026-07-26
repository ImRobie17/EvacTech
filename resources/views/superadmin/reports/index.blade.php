@extends('layouts.superadmin')

@section('title', 'Report Generation')
@section('page-title', 'Report Generation')
@section('page-subtitle', 'System-level reports across all users and activity.')

@section('content')
<div class="card panel" style="max-width: 640px;">
    <h2 class="panel-title">Generate a System Report</h2>
    <form method="POST" action="{{ route('super.reports.generate') }}">
        @csrf
        <div class="field">
            <label for="rep-type">Report type</label>
            <select id="rep-type" name="report_type" required>
                <option value="user_accounts">User Accounts</option>
                <option value="audit_summary">Audit Summary</option>
                <option value="system_activity">System Activity</option>
                <option value="login_activity">Login Activity</option>
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
@endsection
