<?php

namespace App\Support;

use App\Models\EvacuationCenter;
use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\VulnerableClassification;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3 item 11a -- every figure printed on the CSWDO IDP Monitoring Form.
 *
 * WHY THIS CLASS EXISTS. The Barangay side and the City Admin side print the
 * identical document from the identical queries. Putting those queries in both
 * ReportControllers would be the four-copies-of-syncMembers() mistake again:
 * that duplication is precisely why the vulnerable-tags bug survived a whole
 * phase. The controllers here validate, log and render. Nothing else.
 *
 * A plain final class like AgeTier and ShelterContext, not a trait -- a trait
 * constant cannot be read as Trait::CONST (gotcha 10), and PROVINCE/CITY below
 * are read from the PDF view.
 *
 * SCOPE. Two shapes, one builder:
 *   forCenter($center) -- one shelter. The normal case; this is what CSWDO
 *                         fills in by hand today, one sheet per centre.
 *   accumulated()      -- every shelter city-wide, headed "Accumulated
 *                         Shelters". Same tables, same maths, no centre filter.
 *
 * WHAT IS DERIVED AND NEVER STORED. All of it. Age tiers are computed on read
 * (AgeTier), Single Headed Household is computed from members_present == 1
 * (Household::scopeSingleHeaded), and nothing on this form is written to a
 * column. The manual header inputs -- disaster name, disaster date, affected
 * families/persons -- are passed straight through to the view and deliberately
 * not persisted: there is no disasters table, and inventing one for four
 * strings on a printed form is out of scope.
 */
final class IdpForm
{
    /** The report_type string stored on generated_reports. */
    public const TYPE = 'idp_monitoring';

    /**
     * One city, one province. Hardcoded rather than env-driven: EvacTech is
     * deployed for the City of Cabuyao and the barangays table carries no
     * province or city column to read them from.
     */
    public const PROVINCE = 'Laguna';

    public const CITY = 'Cabuyao';

    /** Printed in the header and above table 1 when no single shelter is chosen. */
    public const ACCUMULATED_LABEL = 'Accumulated Shelters';

    /**
     * Table 2 rows, in the exact order the official form lists them.
     *
     * Keyed by classification CODE, never by name -- names are editable in the
     * database and the retired Senior Citizen / Infant tags proved how quickly
     * name-keyed logic rots. 'single_headed' is not a code and never appears in
     * member_vulnerabilities; it is the derived household-level row, spliced in
     * at position 2 where the form puts it.
     */
    private const CATEGORY_ROWS = [
        'pwd' => 'Persons with Disabilities (PWDs)',
        'single_headed' => 'Single Headed Household',
        'pregnant' => 'Pregnant Women',
        'lactating' => 'Lactating Mother/s',
        'solo_parent' => 'Solo Parent',
        'fourps' => '4Ps Beneficiary',
    ];

    /**
     * Phase 3 item 9 -- SCREEN labels for the dashboard charts. Never printed.
     *
     * Deliberately adjacent to CATEGORY_ROWS above. Those strings are
     * transcribed verbatim from the paper CSWDO form and must not be reworded;
     * these exist only because "Persons with Disabilities (PWDs)" will not fit
     * under a bar on a phone. Keeping both lists in one place means a future
     * edit to either is visible against the other, instead of a chart label
     * quietly drifting away from the form in some Blade file.
     *
     * chronic_illness is here but NOT in CATEGORY_ROWS: it is a live internal
     * medical-desk tag, selectable by staff, and deliberately absent from the
     * government form. Only the City Admin dashboard asks for it.
     */
    private const CATEGORY_SHORT_LABELS = [
        'pwd' => 'PWDs',
        'single_headed' => 'Single-Headed',
        'pregnant' => 'Pregnant',
        'lactating' => 'Lactating',
        'solo_parent' => 'Solo Parent',
        'fourps' => '4Ps',
        'chronic_illness' => 'Chronic Illness',
    ];

    /** Selectable but not reportable. See CATEGORY_SHORT_LABELS. */
    private const CHRONIC_ILLNESS_CODE = 'chronic_illness';

    /** One shelter. */
    public static function forCenter(EvacuationCenter $center): array
    {
        return self::build($center);
    }

    /** Every shelter, city-wide. */
    public static function accumulated(): array
    {
        return self::build(null);
    }

    /**
     * Families and persons currently inside scope.
     *
     * Used to pre-fill the two "Number of Affected ..." inputs on the report
     * screen. These are NOT the same figure the form asks for: the official
     * fields mean everyone the disaster affected city-wide, including families
     * who never evacuated, which no query here can know. Pre-filled and
     * labelled as an in-shelter headcount so the operator can overwrite it with
     * the CDRRMO number, or print it as-is and know exactly what it says.
     */
    public static function headcount(?EvacuationCenter $center = null): array
    {
        $households = Household::where('status', 'checked_in')
            ->when($center, fn ($q) => $q->where('evacuation_center_id', $center->id));

        /* PHASE 10A -- city-wide family totals DEDUPLICATE by link; per-shelter
           totals do not.

           A confirmed separated member creates a fragment household at her own
           shelter that points at her family's household. City-wide, that is ONE
           affected family in two places and counting it twice overstates the
           figure. Per shelter, shelter B really is sheltering a fragment of a
           family and its own count must say so -- which is why the exclusion is
           applied only when there is no centre.

           PERSONS is deliberately NOT deduplicated. The move guarantees one
           person exists exactly once, so summing members_present across every
           shelter already counts each human being once. */
        $families = (clone $households)
            ->when(! $center, fn ($q) => $q->whereNull('separated_from_household_id'))
            ->count();

        return [
            'families' => (int) $families,
            'persons' => (int) (clone $households)->sum('members_present'),
        ];
    }

    /**
     * Phase 3 item 9 -- the vulnerable-category chart on both dashboards.
     *
     * A NARROW method on purpose. forCenter() would return these rows too, but
     * it also runs the age matrix and the affected-families headcount, which a
     * chart does not need. Putting the query here rather than in the two
     * DashboardControllers is the whole reason this class exists: a chart and a
     * signed form built from two hand-written copies of the same query would
     * drift, and that is the mistake that let the vulnerable-tags bug live
     * through a phase.
     *
     * TOTAL IS male + female HERE, and that is the one place this method
     * deliberately differs from the printed form. categoryRows() takes the
     * Single Headed total from the household count instead, so a household
     * whose member flags briefly lag members_present during a transfer receipt
     * is never dropped from a signed document. On a chart, a bar whose height
     * disagrees with its own male/female breakdown is worse than a bar that is
     * momentarily one short, so the two are computed differently ON PURPOSE.
     * Sex has been required at registration since Phase 2, so on current data
     * they are identical.
     *
     * @param  EvacuationCenter|null  $center     null means city-wide
     * @param  bool  $includeChronicIllness       City Admin only; off the form
     * @return array<int, array{key:string,label:string,male:int,female:int,total:int}>
     */
    public static function categoriesFor(
        ?EvacuationCenter $center = null,
        bool $includeChronicIllness = false
    ): array {
        $codes = VulnerableClassification::REPORTABLE_CODES;
        if ($includeChronicIllness) {
            $codes[] = self::CHRONIC_ILLNESS_CODE;
        }

        $tagged = self::taggedCounts($center, $codes);
        $single = self::singleHeadedCounts($center);

        // Form order first, then chronic illness last -- it is not on the form,
        // so it must not be interleaved with the rows that are.
        $keys = array_keys(self::CATEGORY_ROWS);
        if ($includeChronicIllness) {
            $keys[] = self::CHRONIC_ILLNESS_CODE;
        }

        $rows = [];
        foreach ($keys as $key) {
            $counts = $key === 'single_headed'
                ? $single
                : ($tagged[$key] ?? ['male' => 0, 'female' => 0, 'total' => 0]);

            $male = (int) $counts['male'];
            $female = (int) $counts['female'];

            $rows[] = [
                'key' => $key,
                'label' => self::CATEGORY_SHORT_LABELS[$key] ?? $key,
                'male' => $male,
                'female' => $female,
                'total' => $male + $female,
            ];
        }

        return $rows;
    }

    /**
     * @param  EvacuationCenter|null  $center  null means accumulate every shelter
     */
    private static function build(?EvacuationCenter $center): array
    {
        $headcount = self::headcount($center);

        return [
            'accumulated' => $center === null,
            'province' => self::PROVINCE,
            'city' => self::CITY,
            'shelterName' => $center?->name ?? self::ACCUMULATED_LABEL,
            'shelterLocation' => $center?->address ?? 'All evacuation centers, City of ' . self::CITY,
            'barangayName' => $center
                ? ($center->barangay?->name ?? 'Not assigned')
                : 'All barangays',
            'ageRows' => self::ageRows($center),
            'categoryRows' => self::categoryRows($center),
            'families' => $headcount['families'],
            'persons' => $headcount['persons'],
        ];
    }

    /**
     * Members counted by this form: present, in a checked-in household, inside
     * scope. Identical to the query behind $ageMatrix on both dashboards, so
     * the signed form and the Phase 3 charts can never disagree.
     */
    private static function memberQuery(?EvacuationCenter $center)
    {
        return HouseholdMember::query()
            ->where('household_members.is_present', true)
            ->whereHas('household', fn ($q) => $q
                ->where('status', 'checked_in')
                ->when($center, fn ($q) => $q->where('evacuation_center_id', $center->id)));
    }

    /**
     * Table 1: one row per age tier, plus a reconciling TOTAL.
     *
     * Bucketed and grouped inside AgeTier::sexMatrixFor(), which wraps the tier
     * CASE in a derived table. Grouping by the CASE expression directly trips
     * MySQL's ONLY_FULL_GROUP_BY with error 1055 -- that already cost one live
     * 500 and must not be reintroduced here.
     *
     * The Unknown row is fetched but printed only when it holds someone.
     * Registration requires a birthdate OR an age group and requires sex, so
     * Unknown can now only come from rows created before Phase 2. Suppressing
     * an empty Unknown keeps the printed sheet to the seven official rows;
     * printing a populated one means the sheet never quietly loses a person.
     */
    private static function ageRows(?EvacuationCenter $center): array
    {
        $matrix = AgeTier::sexMatrixFor(
            self::memberQuery($center),
            includeUnknown: true
        );

        $rows = [];
        $totals = ['male' => 0, 'female' => 0, 'total' => 0];

        foreach ($matrix as $tier => $counts) {
            if ($tier === AgeTier::UNKNOWN && $counts['total'] === 0) {
                continue;
            }

            $rows[] = [
                'category' => AgeTier::shortLabel($tier),
                'age' => AgeTier::range($tier),
                'male' => $counts['male'],
                'female' => $counts['female'],
                'total' => $counts['total'],
            ];

            $totals['male'] += $counts['male'];
            $totals['female'] += $counts['female'];
            $totals['total'] += $counts['total'];
        }

        return ['rows' => $rows, 'totals' => $totals];
    }

    /**
     * Table 2: the five reportable classifications plus the derived
     * Single Headed Household row, in form order.
     *
     * Deliberately has NO total row. The categories overlap -- one person can be
     * a solo parent, a 4Ps beneficiary and a PWD at once -- so a column sum
     * would be a number that means nothing on a document a City Social Welfare
     * officer signs.
     */
    private static function categoryRows(?EvacuationCenter $center): array
    {
        $tagged = self::taggedCounts($center);
        $single = self::singleHeadedCounts($center);

        $rows = [];
        foreach (self::CATEGORY_ROWS as $key => $label) {
            $counts = $key === 'single_headed'
                ? $single
                : ($tagged[$key] ?? ['male' => 0, 'female' => 0, 'total' => 0]);

            $rows[] = [
                'category' => $label,
                'male' => $counts['male'],
                'female' => $counts['female'],
                'total' => $counts['total'],
            ];
        }

        return $rows;
    }

    /**
     * The five whitelisted classifications, counted by code and sex.
     *
     * COUNT(DISTINCT household_members.id) because the pivot can hold more than
     * one row per member and a member must count once per category.
     *
     * Restricted to REPORTABLE_CODES rather than to is_selectable: Person with
     * Chronic Illness is a live internal tag that is simply not on this form,
     * and a future internal tag must never appear on it by accident.
     *
     * Phase 3 item 9 added the $codes parameter. It DEFAULTS to
     * REPORTABLE_CODES, so every existing caller -- meaning the form -- is
     * unchanged. Only categoriesFor() ever passes anything else, and only to
     * add chronic illness to the City Admin chart.
     *
     * Only code and sex are selected, and both are in the GROUP BY, so this is
     * legal under ONLY_FULL_GROUP_BY. Labels come from CATEGORY_ROWS in PHP --
     * never select vulnerable_classifications.name here just to display it.
     */
    private static function taggedCounts(?EvacuationCenter $center, ?array $codes = null): array
    {
        $codes ??= VulnerableClassification::REPORTABLE_CODES;

        $rows = DB::table('member_vulnerabilities')
            ->join(
                'vulnerable_classifications',
                'member_vulnerabilities.vulnerable_classification_id',
                '=',
                'vulnerable_classifications.id'
            )
            ->join(
                'household_members',
                'member_vulnerabilities.household_member_id',
                '=',
                'household_members.id'
            )
            ->join('households', 'household_members.household_id', '=', 'households.id')
            ->where('household_members.is_present', true)
            ->where('households.status', 'checked_in')
            ->when($center, fn ($q) => $q->where('households.evacuation_center_id', $center->id))
            ->whereIn('vulnerable_classifications.code', $codes)
            ->groupBy('vulnerable_classifications.code', 'household_members.sex')
            ->selectRaw('vulnerable_classifications.code as code, household_members.sex as sex, COUNT(DISTINCT household_members.id) as total')
            ->get();

        return self::foldBySex($rows);
    }

    /**
     * Single Headed Household, derived and never stored.
     *
     * A checked-in family with members_present == 1, split by that one person's
     * sex. Confirmed with Cabuyao's shelter operations manager: the flag is
     * defined by check-in state, so it correctly does not exist before check-in
     * and clears itself when the family checks out or the rest of them arrive.
     *
     * TOTAL comes from the household count, not from summing male and female.
     * members_present is authoritative; the per-member is_present flags can
     * legitimately lag it for a moment during a transfer receipt, and a printed
     * sheet must not lose a household to that gap.
     */
    private static function singleHeadedCounts(?EvacuationCenter $center): array
    {
        $households = Household::singleHeaded()
            ->when($center, fn ($q) => $q->where('evacuation_center_id', $center->id));

        $total = (int) (clone $households)->count();

        $bySex = DB::table('households')
            ->join('household_members', 'household_members.household_id', '=', 'households.id')
            ->where('households.status', 'checked_in')
            ->where('households.members_present', 1)
            // PHASE 10A. This clause is a HAND COPY of scopeSingleHeaded(),
            // because a groupBy needs the query builder rather than Eloquent.
            // It must be changed in step with the scope or the printed TOTAL and
            // its own male/female split disagree on a signed form -- and the
            // reader will believe the split.
            ->whereNull('households.separated_from_household_id')
            ->where('household_members.is_present', true)
            ->when($center, fn ($q) => $q->where('households.evacuation_center_id', $center->id))
            ->groupBy('household_members.sex')
            ->selectRaw('household_members.sex as sex, COUNT(DISTINCT households.id) as total')
            ->get();

        $counts = ['male' => 0, 'female' => 0, 'total' => $total];
        foreach ($bySex as $row) {
            if ($row->sex === 'male' || $row->sex === 'female') {
                $counts[$row->sex] += (int) $row->total;
            }
        }

        return $counts;
    }

    /**
     * Fold {code, sex, total} rows into [code => [male, female, total]].
     *
     * total is incremented for every row regardless of sex, exactly as
     * AgeTier::foldSexMatrix() does. Sex is required at registration, so male +
     * female should reconcile with total on current data; on a legacy row with
     * no sex the person still lands in TOTAL rather than vanishing.
     */
    private static function foldBySex(iterable $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            $code = $row->code;
            $sex = $row->sex;
            $total = (int) $row->total;

            $out[$code] ??= ['male' => 0, 'female' => 0, 'total' => 0];

            if ($sex === 'male' || $sex === 'female') {
                $out[$code][$sex] += $total;
            }
            $out[$code]['total'] += $total;
        }

        return $out;
    }
}
