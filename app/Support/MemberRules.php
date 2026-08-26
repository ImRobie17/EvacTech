<?php

namespace App\Support;

use App\Models\VulnerableClassification;
use Illuminate\Http\Request;

/**
 * PHASE 7 ITEMS 2 AND 3 -- the validation rules shared by every screen that
 * writes a member row.
 *
 * There are exactly three of those: Barangay\EvacueeProfilingController
 * (validateHousehold), CityAdmin\EvacueeProfilingController (store) and
 * CityAdmin\ShelterDetailController (updateHousehold). Before this class the
 * birthdate rule was copy-pasted into all three, which is the same shape of
 * problem that let the tags[] bug survive a whole phase in Phase 2: a fix in
 * one place fixed one screen out of three.
 *
 * A plain final class, not a trait: a trait constant cannot be read as
 * Trait::CONST, and MAX_AGE_YEARS needs to be readable from a Blade view so the
 * `min` attribute on the date input and the server rule cannot drift apart.
 */
final class MemberRules
{
    /**
     * The oldest a person in this system may be.
     *
     * 120 is above the oldest verified human lifespan (122) with room to spare,
     * so it rejects typos without ever rejecting a real evacuee. The point is
     * not demographic accuracy -- it is that a mis-keyed year turns a 46-year-old
     * into a 176-year-old Senior Citizen, and that figure then reaches the
     * Senior Citizen row of a form a City Social Welfare officer signs.
     */
    public const MAX_AGE_YEARS = 120;

    /** Resolved once per request -- the closure runs for every member row. */
    private static ?array $femaleOnlyIds = null;

    /** Earliest acceptable date of birth, as Y-m-d. Also the input's `min`. */
    public static function minBirthdate(): string
    {
        return now()->subYears(self::MAX_AGE_YEARS)->toDateString();
    }

    /** Latest acceptable date of birth, as Y-m-d. Also the input's `max`. */
    public static function maxBirthdate(): string
    {
        return now()->toDateString();
    }

    /**
     * Birthdate stays OPTIONAL (Phase 2): staff tag a family fast during a
     * surge and fill birthdays in later. What is new is the lower bound.
     */
    public static function birthdate(): array
    {
        return [
            'nullable',
            'date',
            'before_or_equal:' . self::maxBirthdate(),
            'after_or_equal:' . self::minBirthdate(),
        ];
    }

    /**
     * PHASE 7 ITEM 2 -- Pregnant Woman and Lactating Mother only for a female
     * member, enforced on the server.
     *
     * The UI hides both checkboxes unless sex is Female, but a hidden input is
     * a suggestion, not a rule: the form posts plain ids and anyone can post
     * whatever they like. This closure is the actual enforcement.
     *
     * It keys on `code`, never on `name`, and reads its sibling `sex` field out
     * of the request by index. $attribute arrives as "members.0.tags", so the
     * index is the second segment.
     */
    public static function tags(Request $request): array
    {
        return [
            'nullable',
            'array',
            function (string $attribute, $value, callable $fail) use ($request) {
                $index = explode('.', $attribute)[1] ?? null;
                if ($index === null) {
                    return;
                }

                $sex = $request->input("members.{$index}.sex");

                // A blank or absent sex is already a validation failure of its
                // own (members.*.sex is required). Staying quiet here keeps one
                // mistake from producing two error messages.
                if (! is_string($sex) || $sex === '' || $sex === 'female') {
                    return;
                }

                $offending = array_intersect(
                    array_map('intval', (array) $value),
                    self::femaleOnlyIds()
                );

                if ($offending === []) {
                    return;
                }

                $first = trim((string) $request->input("members.{$index}.first_name", ''));
                $who = $first !== ''
                    ? $first
                    : ($index === '0' ? 'the household head' : 'this member');

                $fail(
                    'Pregnant Woman and Lactating Mother can only be recorded for a female member. '
                    . "Check the sex recorded for {$who}."
                );
            },
        ];
    }

    /** @return array<int, int> */
    private static function femaleOnlyIds(): array
    {
        return self::$femaleOnlyIds ??= VulnerableClassification::femaleOnly()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
