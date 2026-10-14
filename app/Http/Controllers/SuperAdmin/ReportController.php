<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Exports\ArrayExport;
use App\Support\ReportDefinitions;
use App\Support\StandardPdf;
use App\Support\ReportMetrics;
use Illuminate\Validation\Rule;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\SystemEvent;
use App\Models\User;
use App\Services\AuditLogger;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class ReportController extends Controller
{
    public const TYPES = ['user_accounts', 'audit_summary', 'system_activity', 'login_activity'];

    public function index()
    {
        return view('superadmin.reports.index', ['reportDefinitions' => ReportDefinitions::forTypes(self::TYPES)]);
    }

    public function generate(Request $request)
    {
        $data = $request->validate([
            'report_type' => ['required', 'in:' . implode(',', self::TYPES)],
            'format' => ['required', 'in:pdf,xlsx'],
            // DROP D. Preview is a second SUBMIT BUTTON, not a third format --
            // see Controller::pdfResponse(). This controller has no member-level
            // report types, so it gains the preview control and nothing else:
            // the Household Head and contact columns are Barangay and CityAdmin
            // only.
            'action' => ['nullable', 'in:preview'],
            'date_from' => ['nullable', 'date', 'before_or_equal:today', Rule::prohibitedIf(fn () => ! ReportDefinitions::supportsDates((string) $request->input('report_type')))],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from', 'before_or_equal:today', Rule::prohibitedIf(fn () => ! ReportDefinitions::supportsDates((string) $request->input('report_type')))],
        ]);

        $preview = ($data['action'] ?? null) === 'preview';

        // A spreadsheet cannot be rendered inline by a browser. Refuse the
        // combination out loud instead of quietly downloading.
        if ($preview && $data['format'] === 'xlsx') {
            return back()->withInput()->withErrors([
                'format' => 'Excel files cannot be previewed. Choose PDF to preview, or press Generate to download the spreadsheet.',
            ]);
        }

        $context = ReportDefinitions::context($data['report_type'], $data['date_from'] ?? null, $data['date_to'] ?? null);

        [$headings, $rows, $title] = $this->buildDataset($data['report_type'], $data['date_from'] ?? null, $data['date_to'] ?? null);

        $metrics = ReportMetrics::forRows($data['report_type'], $headings, $rows);

        // DROP D. A preview IS logged, the same as a download. These reports
        // list user accounts and login activity; a read that leaves no trace is
        // the wrong default on this screen in particular.
        $verb = $preview ? 'Previewed' : 'Generated';
        AuditLogger::log('created', null, "{$verb} system report: {$title} ({$data['format']}; {$context})");

        $filename = str_replace(' ', '_', strtolower($title)) . '_' . now()->format('Ymd_His');

        if ($data['format'] === 'xlsx') {
            return Excel::download(new ArrayExport($headings, $rows, [
                'title' => $title, 'scope' => 'System-wide', 'context' => $context, 'metrics' => $metrics,
                'generated_by' => auth()->user()->name, 'generated_at' => now()->format('M d, Y h:i A'),
                'sensitive' => ReportDefinitions::isSensitive($data['report_type']),
                'reference' => null,
            ]), $filename . '.xlsx');
        }

        $pdf = Pdf::loadView('reports.pdf.generic', [
            'title' => $title,
            'context' => $context,
            'metrics' => $metrics,
            'sensitive' => ReportDefinitions::isSensitive($data['report_type']),
            'reference' => null,
            'center' => null,
            'scopeLabel' => 'System-wide',
            'headings' => $headings,
            'rows' => $rows,
            'from' => $data['date_from'] ?? null,
            'to' => $data['date_to'] ?? null,
            'generatedBy' => auth()->user()->name,
        ])->setPaper('a4', count($headings) <= 6 && ! in_array($data['report_type'], ['audit_summary', 'system_activity'], true) ? 'portrait' : 'landscape');

        StandardPdf::addPageNumbers($pdf);
        return $this->pdfResponse($pdf, $filename . '.pdf', $preview);
    }

    /** @return array{0: string[], 1: array[], 2: string} */
    private function buildDataset(string $type, ?string $from, ?string $to): array
    {
        $range = fn ($q, $col = 'created_at') => $q
            ->when($from, fn ($q) => $q->whereDate($col, '>=', $from))
            ->when($to, fn ($q) => $q->whereDate($col, '<=', $to));

        return match ($type) {
            'user_accounts' => [
                ['Name', 'Email', 'Role', 'Barangay', 'Status', 'Last Login', 'Created'],
                User::with(['role', 'barangay'])->get()->map(fn ($u) => [
                    $u->name, $u->email, $u->role?->display_name ?? '-',
                    $u->barangay?->name ?? '-', ucfirst($u->status),
                    $u->last_login_at?->format('M d, Y h:i A') ?? 'Never',
                    $u->created_at->format('M d, Y'),
                ])->all(),
                'User Accounts',
            ],
            'audit_summary' => [
                ['Date', 'User', 'Action', 'Description'],
                $range(AuditLog::with('user'))->latest('created_at')->limit(5000)->get()->map(fn ($l) => [
                    $l->created_at?->format('M d, Y h:i A'),
                    $l->user?->name ?? 'System',
                    ucfirst($l->action),
                    $l->description,
                ])->all(),
                'Audit Summary',
            ],
            'system_activity' => [
                ['Date', 'Type', 'Description', 'Performed By', 'Details'],
                $range(SystemEvent::with('performedBy'))->orderByDesc('created_at')->get()->map(fn ($e) => [
                    $e->created_at?->format('M d, Y h:i A'),
                    ucfirst(str_replace('_', ' ', $e->type)),
                    $e->description,
                    $e->performedBy?->name ?? 'System',
                    is_array($e->meta) ? json_encode($e->meta, JSON_UNESCAPED_UNICODE) : ($e->meta ?? ''),
                ])->all(),
                'System Activity',
            ],
            'login_activity' => [
                ['Name', 'Email', 'Role', 'Last Login', 'Status'],
                $range(User::with('role')->whereNotNull('last_login_at'), 'last_login_at')
                    ->orderByDesc('last_login_at')->get()->map(fn ($u) => [
                        $u->name, $u->email, $u->role?->display_name ?? '-',
                        $u->last_login_at?->format('M d, Y h:i A'), ucfirst($u->status),
                    ])->all(),
                'Login Activity',
            ],
        };
    }
}
