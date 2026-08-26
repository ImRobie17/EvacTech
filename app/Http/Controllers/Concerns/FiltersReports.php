<?php

namespace App\Http\Controllers\Concerns;

use App\Models\VulnerableClassification;
use App\Support\AgeTier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * PHASE 3 ITEM 11b -- the sex / age group / vulnerable category report filters.
 *
 * WHY A TRAIT, AND WHY HERE.
 *
 * Barangay\ReportController and CityAdmin\ReportController generate the same
 * reports over different scopes. If this logic were copy-pasted into both, a
 * filtered city report and a filtered barangay report would eventually stop
 * agreeing -- which is exactly what four copies of syncMembers() did to the
 * vulnerable tags in Phase 2. It lives in Http/Controllers/Concerns beside
 * RendersIdpForm, which is already shared by these same two controllers for the
 * same reason, rather than in Support/, so that report code stays in the report
 * layer.
 *
 * NO CONSTANTS LIVE HERE. A trait constant cannot be read as Trait::CONST
 * (gotcha 10). Everything below reads from AgeTier and VulnerableClassification,
 * which already own those lists.
 *
 * ---------------------------------------------------------------------------
 * THE ONE IDEA IN THIS FILE
 *
 * All three filters are properties of a MEMBER. applyMemberFilters() is the only
 * place any of them is expressed; every other depth composes from it through a
 * whereHas:
 *
 *   member level   (Vulnerable, Demographics)  the members themselves
 *   household      (Registry, Attendance)      households with >= 1 match
 *   relief         (Relief Distribution)       distributions to those households
 *   shelter        (Occupancy, Ranking)        shelters with >= 1 match
 *
 * So a filter can never mean one thing on one report and something else on
 * another. What DOES change is what the surrounding numbers count -- a filtered
 * Household Registry still prints whole-household family sizes -- which is why
 * reportFilterNote() exists and why the Matching Members column is added.
 */
trait FiltersReports
{
    // ---------------------------------------------------------------
    // Request side
    // ---------------------------------------------------------------

    /**
     * Rules to merge into each controller's generate() validation.
     *
     * All three are nullable: an unfiltered report is the normal case and must
     * stay one submit away.
     */
    protected function reportFilterRules(): array
    {
        return [
            'sex' => ['nullable', 'in:male,female'],
            'age_tier' => ['nullable', 'in:' . implode(',', AgeTier::keys())],
            'category' => ['nullable', 'in:' . implode(',', array_keys($this->reportFilterOptions()['categories']))],
        ];
    }

    /**
     * [key => label] lists for the three selects on the report screen, and the
     * lookup used to print them back out in the PDF header.
     */
    protected function reportFilterOptions(): array
    {
        // selectable(), not all: a retired classification must never be
        // offerable as a filter, or a report could be scoped by a category no
        // operator is allowed to apply any more.
        $categories = VulnerableClassification::selectable()
            ->orderBy('name')
            ->pluck('name', 'code')
            ->all();

        // Single Headed Household is DERIVED -- members_present == 1 on a
        // checked-in family -- and is deliberately not a row in
        // vulnerable_classifications, so it is appended rather than queried. It
        // is offered because it is one of the six category rows on the CSWDO IDP
        // form, and a filter list missing it would not match the document these
        // reports exist to support.
        $categories['single_headed'] = 'Single Headed Household';

        return [
            'sexes' => ['male' => 'Male', 'female' => 'Female'],
            'tiers' => AgeTier::options(),
            'categories' => $categories,
        ];
    }

    /**
     * Reduce validated input to the filters that are actually set.
     *
     * Returning [] for "no filters" is what every method below tests against,
     * so an empty string from an untouched select can never be mistaken for a
     * real filter.
     */
    protected function reportFilters(array $validated): array
    {
        $filters = [
            'sex' => $validated['sex'] ?? null,
            'age_tier' => $validated['age_tier'] ?? null,
            'category' => $validated['category'] ?? null,
        ];

        return array_filter($filters, fn ($value) => $value !== null && $value !== '');
    }

    /**
     * PHASE 8 ITEM 1 -- the same two filters, read from a plain GET filter bar.
     *
     * WHY THIS IS NOT reportFilters().
     *
     * reportFilters() takes an ALREADY-VALIDATED array, because the report
     * screen posts through generate() where a 422 is the right answer to bad
     * input. A shelter list is not submitted; it is navigated. Its filters
     * arrive in the query string, survive pagination via withQueryString(), and
     * get pasted into chat messages and bookmarked. Throwing a validation error
     * at an operator because a URL they were sent carries a category code that
     * has since been retired would take a working screen away over something
     * that should simply not filter.
     *
     * So this validates by DISCARDING: anything that is not a live key in
     * reportFilterOptions() becomes null and never reaches a query. That closes
     * the same door validate() would -- no unrecognised value is ever passed to
     * applyMemberFilters(), and in particular no arbitrary string reaches the
     * whereRaw comparison in the age-tier branch -- while degrading to an
     * unfiltered list instead of an error page.
     *
     * Sex is deliberately absent: the shelter screens carry two selects, not
     * three. applyMemberFilters() reads each key with empty(), so a filter set
     * without 'sex' is a normal input, not a special case.
     */
    protected function shelterFilters(Request $request): array
    {
        $options = $this->reportFilterOptions();

        $tier = (string) $request->input('age_tier', '');
        $category = (string) $request->input('category', '');

        return array_filter([
            'age_tier' => array_key_exists($tier, $options['tiers']) ? $tier : null,
            'category' => array_key_exists($category, $options['categories']) ? $category : null,
        ], fn ($value) => $value !== null && $value !== '');
    }

    // ---------------------------------------------------------------
    // Query side
    // ---------------------------------------------------------------

    /**
     * THE PRIMITIVE. Every other depth below composes from this.
     *
     * @param  Builder  $members  a query over household_members
     */
    protected function applyMemberFilters(Builder $members, array $filters): Builder
    {
        if ($filters === []) {
            return $members;
        }

        if (! empty($filters['sex'])) {
            // Column qualified because this method also runs inside whereHas
            // sub-queries and a hasManyThrough, where households is joined in.
            $members->where('household_members.sex', $filters['sex']);
        }

        if (! empty($filters['age_tier'])) {
            // Age tiers are DERIVED on read, so there is no column to compare
            // against. sqlCase() is safe in a WHERE; it is NOT safe in a GROUP
            // BY, where ONLY_FULL_GROUP_BY rejects it with error 1055. Nothing
            // here groups -- anything that needs to goes through
            // AgeTier::sexMatrixFor() instead.
            $members->whereRaw(
                AgeTier::sqlCase('household_members') . ' = ?',
                [$filters['age_tier']]
            );
        }

        if (! empty($filters['category'])) {
            if ($filters['category'] === 'single_headed') {
                // Derived, never stored, so it is a HOUSEHOLD condition even
                // here. scopeSingleHeaded() is the single definition.
                $members->whereHas('household', fn ($household) => $household->singleHeaded());
            } else {
                // activeClassifications, not vulnerableClassifications: the
                // pivot still holds rows for retired categories, and keying on
                // code rather than name is the project-wide rule.
                $members->whereHas(
                    'activeClassifications',
                    fn ($class) => $class->where('vulnerable_classifications.code', $filters['category'])
                );
            }
        }

        return $members;
    }

    /** Members themselves. Used by Vulnerable Population and Demographics. */
    protected function filterMembers(Builder $members, array $filters): Builder
    {
        return $this->applyMemberFilters($members, $filters);
    }

    /**
     * Households containing at least one matching member, counting how many.
     *
     * The count lands on each row as `matching_members` and becomes the
     * Matching Members column, so a reader never has to guess whether "Family
     * Size 6" means six matches.
     */
    protected function filterHouseholds(Builder $households, array $filters): Builder
    {
        if ($filters === []) {
            return $households;
        }

        return $households
            ->whereHas('members', fn ($members) => $this->applyMemberFilters($members, $filters))
            ->withCount([
                'members as matching_members' => fn ($members) => $this->applyMemberFilters($members, $filters),
            ]);
    }

    /** Distributions to households containing at least one matching member. */
    protected function filterRelief(Builder $transactions, array $filters): Builder
    {
        if ($filters === []) {
            return $transactions;
        }

        return $transactions->whereHas(
            'household.members',
            fn ($members) => $this->applyMemberFilters($members, $filters)
        );
    }

    /**
     * Shelters containing at least one matching member, counting how many.
     *
     * Counts through EvacuationCenter::householdMembers(), added for this.
     * withCount('households') would count FAMILIES, which is the wrong unit for
     * "how many people here matched".
     */
    protected function filterShelters(Builder $centers, array $filters): Builder
    {
        if ($filters === []) {
            return $centers;
        }

        return $centers
            ->whereHas('households.members', fn ($members) => $this->applyMemberFilters($members, $filters))
            ->withCount([
                'householdMembers as matching_members' => fn ($members) => $this->applyMemberFilters($members, $filters),
            ]);
    }

    // ---------------------------------------------------------------
    // Output side
    // ---------------------------------------------------------------

    /**
     * Append one cell to a heading row or a data row, but only when a filter is
     * active. Unfiltered reports keep exactly the columns they had before.
     *
     * $value is either the heading text, or the MODEL carrying the count --
     * never the count itself. That distinction matters: filterHouseholds() and
     * filterShelters() only attach the withCount when a filter is set, so on an
     * unfiltered report `$model->matching_members` is an attribute that does not
     * exist. Eloquent returns null for that today, but it throws
     * MissingAttributeException the moment anyone calls
     * Model::preventAccessingMissingAttributes() -- and that would fatal every
     * unfiltered report, on a line that looks completely innocent. Reading the
     * raw attribute array here bypasses the magic getter entirely.
     */
    protected function withMatchColumn(array $base, array $filters, $value): array
    {
        if ($filters === []) {
            return $base;
        }

        $base[] = $value instanceof Model
            ? (int) ($value->getAttributes()['matching_members'] ?? 0)
            : $value;

        return $base;
    }

    /** Human-readable filter lines for the PDF header. */
    protected function reportFilterLabels(array $filters): array
    {
        if ($filters === []) {
            return [];
        }

        $options = $this->reportFilterOptions();
        $labels = [];

        if (! empty($filters['sex'])) {
            $labels[] = 'Sex: ' . ($options['sexes'][$filters['sex']] ?? $filters['sex']);
        }

        if (! empty($filters['age_tier'])) {
            $labels[] = 'Age group: ' . AgeTier::label($filters['age_tier'])
                . ' (' . AgeTier::range($filters['age_tier']) . ')';
        }

        if (! empty($filters['category'])) {
            $labels[] = 'Category: ' . ($options['categories'][$filters['category']] ?? $filters['category']);
        }

        return $labels;
    }

    /**
     * The sentence that keeps a filtered report honest.
     *
     * On a member-level report every printed row IS a match, so there is nothing
     * to disclaim. On the others the filter chooses which rows appear and does
     * NOT recompute the numbers inside them -- and a reader who takes
     * "Occupancy 120" beside "Category: Pregnant" at face value has been
     * misled. Returns null where no note is needed.
     */
    protected function reportFilterNote(string $type, array $filters): ?string
    {
        if ($filters === []) {
            return null;
        }

        return match ($type) {
            'vulnerable', 'demographics' => null,
            'relief' => 'Rows are distributions to households containing at least one matching member. '
                . 'Quantities are for the whole distribution, not for the matching members alone.',
            'occupancy', 'shelter_ranking' => 'Rows are shelters containing at least one matching member. '
                . 'Capacity, occupancy and household counts are whole-shelter figures.',
            default => 'Rows are households containing at least one matching member. '
                . 'Family size and members present are whole-household figures.',
        };
    }

    /**
     * Filter codes folded into the download filename.
     *
     * The PDF prints its filters in the header, but ArrayExport is a flat
     * headings/rows sheet with nowhere to put them. Without this, two xlsx files
     * generated a minute apart from the same report type are indistinguishable
     * once they are sitting in a Downloads folder.
     */
    protected function reportFilterSlug(array $filters): string
    {
        if ($filters === []) {
            return '';
        }

        return '_' . implode('_', array_values($filters));
    }
}
