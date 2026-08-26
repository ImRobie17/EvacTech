<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Exports\ArrayExport;
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
        return view('superadmin.reports.index');
    }

    public function generate(Request $request)
    {
        $data = $request->validate([
            'report_type' => ['required', 'in:' . implode(',', self::TYPES)],
            'format' => ['required', 'in:pdf,xlsx'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        [$headings, $rows, $title] = $this->buildDataset($data['report_type'], $data['date_from'] ?? null, $data['date_to'] ?? null);

        AuditLogger::log('created', null, "Generated system report: {$title} ({$data['format']})");

        $filename = str_replace(' ', '_', strtolower($title)) . '_' . now()->format('Ymd_His');

        if ($data['format'] === 'xlsx') {
            return Excel::download(new ArrayExport($headings, $rows), $filename . '.xlsx');
        }

        $pdf = Pdf::loadView('reports.pdf.generic', [
            'title' => $title,
            'center' => null,
            'scopeLabel' => 'System-wide',
            'headings' => $headings,
            'rows' => $rows,
            'from' => $data['date_from'] ?? null,
            'to' => $data['date_to'] ?? null,
            'generatedBy' => auth()->user()->name,
        ])->setPaper('a4', 'landscape');

        return $pdf->download($filename . '.pdf');
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
                    $e->meta ?? '',
                ])->all(),
                'System Activity',
            ],
            'login_activity' => [
                ['Name', 'Email', 'Role', 'Last Login', 'Status'],
                User::with('role')->whereNotNull('last_login_at')
                    ->orderByDesc('last_login_at')->get()->map(fn ($u) => [
                        $u->name, $u->email, $u->role?->display_name ?? '-',
                        $u->last_login_at?->format('M d, Y h:i A'), ucfirst($u->status),
                    ])->all(),
                'Login Activity',
            ],
        };
    }
}
