<?php

namespace App\Support;

use App\Models\Household;
use Illuminate\Database\QueryException;
use Throwable;

/**
 * Gotcha 11, fixed.
 *
 * The old nextCode() built HH-{year}-{count+1} from a count() against a column
 * with a UNIQUE index. Two bugs, one of them guaranteed:
 *
 *   1. RACE. Two staff hit Save at the same moment, both read 41, both build
 *      HH-2026-00042, and the second insert violates the unique index. The
 *      operator loses the entire form to a 500 during exactly the surge the
 *      system exists for.
 *
 *   2. DELETION -- not a race, a certainty. destroy() exists and works.
 *      Register 42 households, remove one, count() returns 41, and every
 *      subsequent registration tries HH-2026-00042 forever. This one is trivial
 *      to trigger by accident during a demo.
 *
 * Fixed by deriving from the HIGHEST EXISTING code rather than a row count
 * (which alone kills bug 2 permanently), then retrying on a duplicate-key
 * violation to close the remaining race window.
 */
final class HouseholdCode
{
    private const PAD = 5;

    /** MySQL duplicate-entry error number. */
    private const DUPLICATE_ENTRY = 1062;

    public static function next(): string
    {
        $year = now()->year;
        $prefix = sprintf('HH-%d-', $year);

        // MAX over the numeric suffix. Ignores gaps left by deletions, which is
        // the whole point: codes are identifiers, not a contiguous count.
        $max = Household::query()
            ->where('household_code', 'like', $prefix . '%')
            ->selectRaw(
                'MAX(CAST(SUBSTRING(household_code, ?) AS UNSIGNED)) as seq',
                [strlen($prefix) + 1]
            )
            ->value('seq');

        return $prefix . str_pad((string) (((int) $max) + 1), self::PAD, '0', STR_PAD_LEFT);
    }

    /**
     * Run $callback with a fresh code, retrying if another request took it.
     *
     * IMPORTANT: call this AROUND DB::transaction(), never inside it. A failed
     * insert rolls the transaction back, so the retry needs a clean one.
     *
     *   $household = HouseholdCode::attempt(fn ($code) => DB::transaction(
     *       fn () => Household::create(['household_code' => $code, ...])
     *   ));
     */
    public static function attempt(callable $callback, int $tries = 5)
    {
        for ($attempt = 1; $attempt <= $tries; $attempt++) {
            try {
                return $callback(self::next());
            } catch (QueryException $e) {
                if ($attempt === $tries || ! self::isDuplicateCode($e)) {
                    throw $e;
                }
                // Tiny random backoff so two colliding requests do not simply
                // collide again in lockstep.
                usleep(random_int(10_000, 60_000));
            }
        }

        throw new \RuntimeException('Could not allocate a household code.');
    }

    private static function isDuplicateCode(Throwable $e): bool
    {
        $code = $e->errorInfo[1] ?? null;

        return (int) $code === self::DUPLICATE_ENTRY
            && str_contains($e->getMessage(), 'household_code');
    }
}
