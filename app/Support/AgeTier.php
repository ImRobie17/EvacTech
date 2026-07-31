<?php

namespace App\Support;

use App\Models\HouseholdMember;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Phase 2 item 5 -- the seven CSWDO age tiers.
 *
 * A plain final class, not a trait: a trait constant cannot be read as
 * Trait::CONST (gotcha 10), and every consumer here needs the constants.
 *
 * WHY MONTHS. Year precision collapses Infant (0-6 mo) and Toddler (7 mo-2 yr)
 * into a single "0 years old" bucket. Every boundary below is therefore in
 * months, and the SQL uses TIMESTAMPDIFF(MONTH, ...).
 *
 * WHY DERIVED. The tier is computed on every read and never stored. A member
 * who checks in as an Infant is a Toddler seven months later; a stored tier
 * would quietly go stale during a long stay and put the wrong number on a form
 * a City Social Welfare officer signs. A MySQL generated column is not an
 * option either: generated expressions must be deterministic and CURDATE() is
 * not.
 *
 * WHY BOTH A PHP AND A SQL PATH. Bucket in SQL for dashboard charts and report
 * filters (one grouped query, no hydration); bucket in PHP only for displaying
 * a single record. sqlCase() is written so it can be grouped by tier AND by
 * sex simultaneously, which is exactly the shape the Phase 3 IDP Monitoring
 * Form needs -- see groupedBySexQuery() for the ready-made version.
 */
final class AgeTier
{
    public const INFANT = 'infant';
    public const TODDLER = 'toddler';
    public const PRESCHOOL = 'preschool';
    public const SCHOOL_AGE = 'school_age';
    public const TEENAGE = 'teenage';
    public const ADULT = 'adult';
    public const SENIOR = 'senior';
    public const UNKNOWN = 'unknown';

    /**
     * Upper bound in months, EXCLUSIVE, in ascending order.
     *
     * Infant      0-6 months      -> under 7
     * Toddler     7 months-2 yrs  -> under 36  (a 2-year-old is a Toddler until
     *                                           the third birthday; without this
     *                                           months 24-35 belong to no tier)
     * Pre-school  3-5 yrs         -> under 72
     * School Age  6-12 yrs        -> under 156
     * Teenage     13-17 yrs       -> under 216
     * Adult       18-59 yrs       -> under 720
     * Senior      60+ yrs         -> everything else
     */
    private const BOUNDS = [
        self::INFANT => 7,
        self::TODDLER => 36,
        self::PRESCHOOL => 72,
        self::SCHOOL_AGE => 156,
        self::TEENAGE => 216,
        self::ADULT => 720,
    ];

    private const LABELS = [
        self::INFANT => 'Infant (0-6 months)',
        self::TODDLER => 'Toddler (7 months-2 years)',
        self::PRESCHOOL => 'Pre-school (3-5 years)',
        self::SCHOOL_AGE => 'School Age (6-12 years)',
        self::TEENAGE => 'Teenage (13-17 years)',
        self::ADULT => 'Adult (18-59 years)',
        self::SENIOR => 'Senior Citizen (60+ years)',
        self::UNKNOWN => 'Unknown',
    ];

    /** Short labels for the IDP form's Age column and for dashboard chart axes. */
    private const SHORT_LABELS = [
        self::INFANT => 'Infant',
        self::TODDLER => 'Toddler',
        self::PRESCHOOL => 'Pre-school',
        self::SCHOOL_AGE => 'School Age',
        self::TEENAGE => 'Teenage',
        self::ADULT => 'Adult',
        self::SENIOR => 'Senior Citizen',
        self::UNKNOWN => 'Unknown',
    ];

    /**
     * The bracket text for the IDP Monitoring Form's Age column.
     *
     * The official form splits Category and Age into two columns, so the range
     * cannot be baked into the label the way LABELS does it.
     *
     * TRANSCRIBED VERBATIM from the photographed CSWDO form, including the
     * trailing "old" and the bare "60 and above". Do not tidy the wording:
     * the printed sheet has to read the same as the one it replaces. Plain ASCII
     * hyphens -- these strings reach a PDF and a raw glyph would come back as
     * mojibake.
     */
    private const RANGES = [
        self::INFANT => '0-6 months',
        self::TODDLER => '7 months - 2 years old',
        self::PRESCHOOL => '3-5 years old',
        self::SCHOOL_AGE => '6-12 years old',
        self::TEENAGE => '13-17 years old',
        self::ADULT => '18-59 years old',
        self::SENIOR => '60 and above',
        self::UNKNOWN => 'Not stated',
    ];

    /** The seven real tiers, in official form order. Excludes Unknown. */
    public static function keys(): array
    {
        return [
            self::INFANT, self::TODDLER, self::PRESCHOOL, self::SCHOOL_AGE,
            self::TEENAGE, self::ADULT, self::SENIOR,
        ];
    }

    /** [key => label] for the registration dropdown and filter selects. */
    public static function options(): array
    {
        $out = [];
        foreach (self::keys() as $key) {
            $out[$key] = self::LABELS[$key];
        }

        return $out;
    }

    public static function label(?string $key): string
    {
        return self::LABELS[$key] ?? self::LABELS[self::UNKNOWN];
    }

    public static function shortLabel(?string $key): string
    {
        return self::SHORT_LABELS[$key] ?? self::SHORT_LABELS[self::UNKNOWN];
    }

    /** The Age column of the IDP form, e.g. "7 months - 2 years". */
    public static function range(?string $key): string
    {
        return self::RANGES[$key] ?? self::RANGES[self::UNKNOWN];
    }

    /**
     * Phase 3 item 9 -- fold a sex matrix into labelled rows for a dashboard.
     *
     * Takes the output of sexMatrixFor() / emptySexMatrix() and returns
     * [['key','label','male','female','total'], ...] using the SHORT labels,
     * which are what fit under a chart segment and inside a narrow table cell.
     *
     * The Unknown bucket is dropped when it holds nobody. Registration has
     * required a birthdate or an age group since Phase 2, so a populated
     * Unknown can only come from a pre-Phase-2 row; charting an empty slice
     * would put a permanent meaningless label on every dashboard in the city.
     * A populated one is always shown, so nobody silently vanishes.
     *
     * Lives here, not in a Blade file, because both dashboards need it and no
     * view in this codebase references a class directly -- controllers prepare,
     * views render. Takes an array and runs no queries, so it stays a pure
     * formatter.
     */
    public static function chartRows(array $matrix): array
    {
        $rows = [];

        foreach ($matrix as $tier => $counts) {
            $total = (int) ($counts['total'] ?? 0);

            if ($tier === self::UNKNOWN && $total === 0) {
                continue;
            }

            $rows[] = [
                'key' => $tier,
                'label' => self::shortLabel($tier),
                'male' => (int) ($counts['male'] ?? 0),
                'female' => (int) ($counts['female'] ?? 0),
                'total' => $total,
            ];
        }

        return $rows;
    }

    public static function isValid(?string $key): bool
    {
        return $key !== null && in_array($key, self::keys(), true);
    }

    /**
     * Whole months elapsed.
     *
     * Deliberately NOT Carbon's diffInMonths(): its return type and its
     * absolute/signed default changed between Carbon 2 and 3, and a silent
     * off-by-one here would move children between tiers on the signed form.
     * This is the exact arithmetic MySQL's TIMESTAMPDIFF(MONTH, ...) performs,
     * so the PHP path and the SQL path can never disagree at a boundary.
     */
    public static function monthsSince(?Carbon $birthdate): ?int
    {
        if (! $birthdate) {
            return null;
        }

        $birth = $birthdate->copy()->startOfDay();
        $today = Carbon::today();

        $months = (($today->year - $birth->year) * 12) + ($today->month - $birth->month);

        // Not yet past the day-of-month, so the final month is incomplete.
        if ($today->day < $birth->day) {
            $months--;
        }

        return max(0, $months);
    }

    public static function fromMonths(?int $months): string
    {
        if ($months === null || $months < 0) {
            return self::UNKNOWN;
        }

        foreach (self::BOUNDS as $key => $upperExclusive) {
            if ($months < $upperExclusive) {
                return $key;
            }
        }

        return self::SENIOR;
    }

    /**
     * The one place precedence is decided. Birthdate ALWAYS wins; the manual
     * fallback is consulted only when there is no birthdate at all.
     */
    public static function forMember(HouseholdMember $member): string
    {
        if ($member->birthdate) {
            return self::fromMonths(self::monthsSince($member->birthdate));
        }

        return self::isValid($member->age_tier_fallback)
            ? $member->age_tier_fallback
            : self::UNKNOWN;
    }

    /**
     * A raw SQL CASE expression returning the tier key.
     *
     * Pass the table name or alias so it is safe inside joins.
     *
     * Safe in a SELECT and in a WHERE. NOT safe in a GROUP BY: MySQL's
     * ONLY_FULL_GROUP_BY cannot prove functional dependency through a nested
     * CASE over TIMESTAMPDIFF and rejects it with error 1055. For anything
     * grouped, use sexMatrixQuery()/sexMatrixFor() below.
     */
    public static function sqlCase(string $table = 'household_members'): string
    {
        $birthdate = "{$table}.birthdate";
        $fallback = "{$table}.age_tier_fallback";
        $months = "TIMESTAMPDIFF(MONTH, {$birthdate}, CURDATE())";

        $branches = '';
        foreach (self::BOUNDS as $key => $upperExclusive) {
            $branches .= " WHEN {$months} < {$upperExclusive} THEN '{$key}'";
        }

        return "CASE"
            . " WHEN {$birthdate} IS NOT NULL THEN (CASE{$branches} ELSE '" . self::SENIOR . "' END)"
            . " WHEN {$fallback} IS NOT NULL AND {$fallback} <> '' THEN {$fallback}"
            . " ELSE '" . self::UNKNOWN . "'"
            . " END";
    }

    /**
     * Build the {tier, sex, total} result set for a given set of members.
     *
     * WHY A DERIVED TABLE and not a plain groupByRaw(sqlCase()).
     *
     * MySQL's default sql_mode includes ONLY_FULL_GROUP_BY. Selecting the CASE
     * expression and grouping by the identical CASE expression LOOKS legal --
     * and is, logically -- but MySQL only proves functional dependency for
     * simple expressions. Faced with a nested CASE wrapping TIMESTAMPDIFF it
     * gives up and rejects the query with:
     *
     *   1055 'household_members.birthdate' isn't in GROUP BY
     *
     * Computing the tier in an inner query and grouping by the resulting plain
     * column in the outer one sidesteps the check entirely, works on every
     * sql_mode, and does not require touching the server's configuration --
     * which would only move the problem to the next machine the project runs on.
     *
     * @param  EloquentBuilder  $members  a query over household_members,
     *                                    already scoped/filtered by the caller
     */
    public static function sexMatrixQuery(EloquentBuilder $members, string $table = 'household_members'): QueryBuilder
    {
        $inner = $members->clone()->selectRaw(
            self::sqlCase($table) . " as tier, {$table}.sex as sex"
        );

        return DB::query()
            ->fromSub($inner, 'age_tier_rows')
            ->selectRaw('tier, sex, COUNT(*) as total')
            ->groupBy('tier', 'sex');
    }

    /** Convenience: run the query above and fold it straight into the matrix. */
    public static function sexMatrixFor(
        EloquentBuilder $members,
        string $table = 'household_members',
        bool $includeUnknown = false
    ): array {
        return self::foldSexMatrix(
            self::sexMatrixQuery($members, $table)->get(),
            $includeUnknown
        );
    }

    /**
     * Zero-filled [tierKey => ['male' => 0, 'female' => 0, 'total' => 0]].
     *
     * The IDP form prints all seven rows whether or not anyone falls in them,
     * so counts are merged into this skeleton rather than driving the rows.
     */
    public static function emptySexMatrix(bool $includeUnknown = false): array
    {
        $keys = self::keys();
        if ($includeUnknown) {
            $keys[] = self::UNKNOWN;
        }

        $out = [];
        foreach ($keys as $key) {
            $out[$key] = ['male' => 0, 'female' => 0, 'total' => 0];
        }

        return $out;
    }

    /**
     * Fold a result set of {tier, sex, total} rows into the matrix above.
     * Phase 3's IDP form calls this; the dashboard charts reuse it too.
     */
    public static function foldSexMatrix(iterable $rows, bool $includeUnknown = false): array
    {
        $matrix = self::emptySexMatrix($includeUnknown);

        foreach ($rows as $row) {
            $tier = is_array($row) ? ($row['tier'] ?? null) : ($row->tier ?? null);
            $sex = is_array($row) ? ($row['sex'] ?? null) : ($row->sex ?? null);
            $total = (int) (is_array($row) ? ($row['total'] ?? 0) : ($row->total ?? 0));

            if (! isset($matrix[$tier])) {
                continue;
            }
            if ($sex === 'male' || $sex === 'female') {
                $matrix[$tier][$sex] += $total;
            }
            $matrix[$tier]['total'] += $total;
        }

        return $matrix;
    }
}
