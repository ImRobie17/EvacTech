<?php

namespace App\Console\Commands;

use App\Models\EvacuationCenter;
use Illuminate\Console\Command;

/**
 * Repairs current_occupancy drift.
 *
 * Occupancy used to be maintained with increment()/decrement() calls in three
 * different controllers. Any failed request left the counter wrong, and the
 * error compounded silently. Occupancy is now DERIVED (see
 * EvacuationCenter::recalcOccupancy) -- this command fixes shelters that were
 * already drifting before the change, and is safe to re-run any time.
 *
 * Usage:  php artisan evactech:recalc-occupancy
 *         php artisan evactech:recalc-occupancy --dry-run
 */
class RecalcOccupancy extends Command
{
    protected $signature = 'evactech:recalc-occupancy {--dry-run : Report drift without writing}';

    protected $description = 'Recalculate every shelter\'s current_occupancy from checked-in households';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $rows = [];
        $fixed = 0;

        foreach (EvacuationCenter::orderBy('name')->get() as $center) {
            $stored = (int) $center->current_occupancy;
            $actual = (int) $center->households()
                ->where('status', 'checked_in')
                ->sum('members_present');

            if ($stored !== $actual) {
                $fixed++;
                if (! $dry) {
                    $center->recalcOccupancy();
                }
            }

            $rows[] = [
                $center->name,
                $stored,
                $actual,
                $stored === $actual ? 'ok' : ($dry ? 'DRIFT' : 'fixed'),
            ];
        }

        $this->table(['Shelter', 'Stored', 'Actual', 'Result'], $rows);

        if ($fixed === 0) {
            $this->info('No drift found.');
        } elseif ($dry) {
            $this->warn("{$fixed} shelter(s) are out of sync. Re-run without --dry-run to fix.");
        } else {
            $this->info("Corrected {$fixed} shelter(s).");
        }

        return self::SUCCESS;
    }
}
