<?php

namespace App\Services;

use App\Models\Role;
use App\Models\SystemAlert;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SystemAlerter
{
    /**
     * Record a system/DB error. Always logs to the system_alerts table and the
     * Laravel log. Emails all Super Admins ONLY when EVACTECH_ALERT_EMAILS=true
     * (so it's ready to flip on once SMTP is configured, per the agreed setup).
     */
    public static function raise(
        string $title,
        ?string $message = null,
        string $level = 'error',
        string $type = 'system',
        ?string $source = null,
    ): SystemAlert {
        $alert = SystemAlert::create([
            'level' => $level,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'source' => $source,
        ]);

        Log::channel(config('logging.default'))->{$level === 'critical' ? 'critical' : ($level === 'warning' ? 'warning' : 'error')}(
            "[EvacTech system alert] {$title}" . ($message ? " - {$message}" : '')
        );

        if (filter_var(env('EVACTECH_ALERT_EMAILS', false), FILTER_VALIDATE_BOOL)) {
            self::emailSuperAdmins($alert);
        }

        return $alert;
    }

    private static function emailSuperAdmins(SystemAlert $alert): void
    {
        try {
            $recipients = User::whereHas('role', fn ($q) => $q->where('name', Role::SUPER_ADMIN))
                ->where('status', 'active')
                ->pluck('email')
                ->filter()
                ->all();

            if (empty($recipients)) {
                return;
            }

            // Plain-text mail keeps this dependency-free until a Mailable/template
            // is added. Flip EVACTECH_ALERT_EMAILS=true once SMTP is set in .env.
            Mail::raw(
                "EvacTech system alert\n\nLevel: {$alert->level}\nType: {$alert->type}\nTitle: {$alert->title}\n\n" .
                    ($alert->message ?? '') . "\n\nLogged at: {$alert->created_at}",
                function ($m) use ($recipients, $alert) {
                    $m->to($recipients)->subject("[EvacTech] {$alert->level}: {$alert->title}");
                }
            );

            $alert->update(['email_sent' => true]);
        } catch (\Throwable $e) {
            // Never let alerting break the request; just log the failure.
            Log::error('Failed to send EvacTech system alert email: ' . $e->getMessage());
        }
    }
}
