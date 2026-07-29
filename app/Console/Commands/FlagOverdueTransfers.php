<?php

namespace App\Console\Commands;

use App\Models\ShelterTransfer;
use App\Models\SystemAlert;
use App\Services\SystemAlerter;
use Illuminate\Console\Command;

/**
 * PHASE 2 ITEM 8 -- optional overdue sweep.
 *
 * READ THIS BEFORE RELYING ON IT: nothing in the user interface depends on this
 * command. The alert bar, the sidebar glow and the Transfers pages all compute
 * "overdue" on read, from departed_at, every time a page is loaded. That is a
 * deliberate choice -- there is no scheduler configured in this project, and a
 * notification system that only worked when Windows Task Scheduler had been set
 * up correctly would silently do nothing on the defence machine.
 *
 * This command exists so a deployment that DOES have a scheduler can also push
 * overdue transfers into system_alerts (and, with EVACTECH_ALERT_EMAILS=true,
 * into Super Admin inboxes) without anyone being logged in. It is a thin
 * wrapper over the same scope the UI uses.
 *
 * To schedule it, add one line to routes/console.php:
 *
 *     Schedule::command('evactech:flag-overdue-transfers')->everyThirtyMinutes();
 *
 * Deliberately NOT added there by this phase: routes/console.php currently has
 * zero scheduled tasks, and adding the first one implies a cron/Task Scheduler
 * entry that does not exist yet.
 */
class FlagOverdueTransfers extends Command
{
    protected $signature = 'evactech:flag-overdue-transfers';

    protected $description = 'Raise a system alert for each shelter transfer that has been in transit too long';

    public function handle(): int
    {
        $overdue = ShelterTransfer::with(['household', 'fromCenter', 'toCenter'])
            ->overdue()
            ->get();

        if ($overdue->isEmpty()) {
            $this->info('No overdue shelter transfers.');

            return self::SUCCESS;
        }

        $raised = 0;

        foreach ($overdue as $transfer) {
            // One alert per transfer, ever. The source column is a plain string,
            // so it doubles as the dedupe key and no extra table is needed.
            $source = 'FlagOverdueTransfers#' . $transfer->id;

            if (SystemAlert::where('source', $source)->exists()) {
                continue;
            }

            SystemAlerter::raise(
                'Shelter transfer overdue',
                sprintf(
                    '%s left %s at %s bound for %s and has not been received after %d minutes.',
                    $transfer->household?->household_code ?? 'A household',
                    $transfer->fromCenter?->name ?? 'its shelter',
                    $transfer->departed_at?->format('M d, Y h:i A') ?? 'an unknown time',
                    $transfer->toCenter?->name ?? 'its destination',
                    (int) $transfer->minutesInTransit()
                ),
                'warning',
                'other',
                $source
            );

            $raised++;
        }

        $this->info(sprintf(
            '%d overdue transfer(s) found, %d new alert(s) raised.',
            $overdue->count(),
            $raised
        ));

        return self::SUCCESS;
    }
}
