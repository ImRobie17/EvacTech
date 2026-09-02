@extends('layouts.superadmin')

@section('title', 'Report Generation')
@section('page-title', 'Report Generation')
@section('page-subtitle', 'System-level reports across all users and activity.')

@section('content')
{{-- max-w-2xl replaces style="max-width: 640px". Same intent -- a five-control
     form should not stretch across a 27-inch monitor -- but as a utility it
     participates in the cascade and can be overridden by a later utility, which
     an inline style cannot. --}}
<div class="card panel max-w-2xl">
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
                <label class="radio-row"><input type="radio" name="format" value="pdf" @checked(old('format', 'pdf') === 'pdf')> PDF (printable)</label>
                <label class="radio-row"><input type="radio" name="format" value="xlsx" @checked(old('format') === 'xlsx')> Excel (.xlsx)</label>
            </div>
            <p class="mt-1 text-xs text-ink-muted">
                Preview opens the PDF in a new tab and downloads nothing.
                Excel files cannot be previewed and will always download.
            </p>
        </div>
        {{-- DROP D. Generate stays btn-primary and stays LAST, so it remains
             the form's default submit -- pressing Enter in a date field still
             downloads rather than previews.

             formtarget="_blank" is on the PREVIEW button only, not on the
             <form>. One attribute, no JS, no bundle rebuild, and the two
             buttons keep different targets from the same form. --}}
        <div class="flex flex-col gap-2 sm:flex-row">
            <button type="submit" name="action" value="preview" formtarget="_blank" class="btn-secondary w-full min-h-[44px] sm:w-auto">Preview</button>
            <button type="submit" class="btn-primary w-full min-h-[44px] sm:w-auto">&darr; Generate &amp; Download</button>
        </div>
    </form>
</div>
@endsection
