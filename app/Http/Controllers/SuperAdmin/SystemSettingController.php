<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\SystemAlert;
use App\Models\SystemEvent;
use App\Services\AuditLogger;
use App\Services\SystemAlerter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SystemSettingController extends Controller
{
    public function index()
    {
        $health = [
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'app_env' => config('app.env'),
            'debug_mode' => (bool) config('app.debug'),
            'maintenance' => (bool) cache()->get('evactech_maintenance', false),
            'db_connection' => $this->dbConnectionOk(),
            'mysqldump' => $this->mysqldumpAvailable(),
            'storage_writable' => is_writable(storage_path()),
        ];

        $events = SystemEvent::with('performedBy')->orderByDesc('created_at')->limit(15)->get();
        $alerts = SystemAlert::latest()->limit(15)->get();

        return view('superadmin.settings.index', compact('health', 'events', 'alerts'));
    }

    /** Stream a mysqldump of the current database as a timestamped .sql download. */
    public function backup()
    {
        $conn = config('database.default');
        $cfg = config("database.connections.{$conn}");

        if (($cfg['driver'] ?? null) !== 'mysql') {
            return back()->withErrors(['backup' => 'Database backup currently supports MySQL only.']);
        }
        if (! $this->mysqldumpAvailable()) {
            SystemAlerter::raise('Backup failed', 'mysqldump is not available on the server PATH.', 'warning', 'backup', __METHOD__);
            return back()->withErrors(['backup' => 'mysqldump was not found on the server. Install MySQL client tools or add mysqldump to PATH.']);
        }

        $filename = 'evactech_backup_' . now()->format('Ymd_His') . '.sql';

        // Build the command safely with escaped args.
        $host = escapeshellarg($cfg['host'] ?? '127.0.0.1');
        $port = escapeshellarg((string) ($cfg['port'] ?? 3306));
        $user = escapeshellarg($cfg['username'] ?? 'root');
        $db = escapeshellarg($cfg['database'] ?? '');
        $passwordEnv = $cfg['password'] ?? '';

        SystemEvent::create([
            'type' => 'backup',
            'description' => 'Database backup downloaded',
            'meta' => $filename,
            'performed_by' => auth()->id(),
            'created_at' => now(),
        ]);
        AuditLogger::log('created', null, "Downloaded database backup ({$filename})");

        // MYSQL_PWD passes the password without exposing it in the process list.
        $command = sprintf(
            'mysqldump --host=%s --port=%s --user=%s --single-transaction --skip-lock-tables %s',
            $host, $port, $user, $db
        );

        return new StreamedResponse(function () use ($command, $passwordEnv) {
            $descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $env = array_merge($_ENV, ['MYSQL_PWD' => $passwordEnv]);
            $process = proc_open($command, $descriptor, $pipes, null, $env);

            if (is_resource($process)) {
                while (! feof($pipes[1])) {
                    echo fread($pipes[1], 8192);
                    flush();
                }
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
            }
        }, 200, [
            'Content-Type' => 'application/sql',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /** Toggle a soft maintenance mode (cache flag) that logs out non-super-admins. */
    public function toggleMaintenance(Request $request)
    {
        $request->validate(['confirmation' => ['required', 'in:maintenance,MAINTENANCE']]);

        $currentlyOn = (bool) cache()->get('evactech_maintenance', false);
        $turnOn = ! $currentlyOn;

        cache()->put('evactech_maintenance', $turnOn, now()->addDays(7));

        SystemEvent::create([
            'type' => $turnOn ? 'maintenance_on' : 'maintenance_off',
            'description' => $turnOn ? 'Maintenance mode enabled' : 'Maintenance mode disabled',
            'performed_by' => auth()->id(),
            'created_at' => now(),
        ]);
        AuditLogger::log('updated', null, $turnOn ? 'Enabled maintenance mode' : 'Disabled maintenance mode');

        return back()->with('success', $turnOn
            ? 'Maintenance mode is ON. Non-super-admin users are now locked out.'
            : 'Maintenance mode is OFF. The system is available to everyone again.');
    }

    /** Mark a system alert as resolved. */
    public function resolveAlert(SystemAlert $alert)
    {
        $alert->update(['is_resolved' => true, 'resolved_at' => now()]);
        return back()->with('success', 'Alert marked as resolved.');
    }

    private function mysqldumpAvailable(): bool
    {
        // 'which' on *nix, 'where' on Windows.
        $cmd = stripos(PHP_OS, 'WIN') === 0 ? 'where mysqldump 2>NUL' : 'command -v mysqldump 2>/dev/null';
        $out = @shell_exec($cmd);
        return ! empty(trim((string) $out));
    }

    private function dbConnectionOk(): bool
    {
        try {
            DB::connection()->getPdo();
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
