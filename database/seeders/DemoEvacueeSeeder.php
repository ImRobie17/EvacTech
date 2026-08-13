<?php

namespace Database\Seeders;

use App\Models\EvacuationCenter;
use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\MemberVulnerability;
use App\Models\VulnerableClassification;
use App\Support\AgeTier;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Demo evacuees.
 *
 * WHAT THIS IS FOR. Empty reports prove nothing at a defence. This fills eight
 * shelters with households whose ages, sexes and vulnerability tags land in
 * every row of the CSWDO IDP Monitoring Form, so the form, both dashboards and
 * the charts all have something to show.
 *
 * DETERMINISTIC. mt_srand() is seeded with a constant, so running this twice
 * produces identical data and a screenshot taken today still matches the
 * database tomorrow.
 *
 * IT DELIBERATELY COVERS THE AWKWARD CASES, not just the happy path:
 *
 *   - every one of the seven age tiers, including Infant (0-6 months), which is
 *     the easiest to leave empty by accident
 *   - members with NO birthdate, carrying age_tier_fallback instead, because
 *     that path has its own derivation rule
 *   - single-headed households (checked in, members_present = 1), which the IDP
 *     form counts in five different places
 *   - households with somebody NOT present, so "did not arrive" and presence
 *     correction have something to act on
 *   - households declared SEPARATED, so the reunification panel is not empty
 *   - registered-but-not-checked-in, and checked-out, so status filters have
 *     more than one value to filter
 *
 * WHAT IT DOES NOT DO. It never writes occupancy. Occupancy is DERIVED --
 * recalcOccupancy() sums members_present over checked-in households -- so the
 * seeder calls that at the end rather than inventing a number that the first
 * recalculation would overwrite.
 *
 * Tags are written through MemberVulnerability directly rather than
 * HouseholdMemberSync::applyTags(), and that is a considered exception: the sync
 * service resolves auth()->id() for tagged_by, which is null in a console
 * context. The seeder therefore applies the female-only rule itself, in the one
 * place below, and never tags a non-female member as pregnant or lactating.
 */
class DemoEvacueeSeeder extends Seeder
{
    private const SURNAMES = [
        'Dela Cruz', 'Santos', 'Reyes', 'Ramos', 'Mercado', 'Bautista', 'Villanueva',
        'Gonzales', 'Aquino', 'Castillo', 'Navarro', 'Domingo', 'Salazar', 'Rivera',
        'Fernandez', 'Manalo', 'Espiritu', 'Bernardo', 'Alcantara', 'Pascual',
        'Cabrera', 'Lorenzo', 'Tolentino', 'Marquez', 'Sarmiento', 'Ocampo',
        'Valdez', 'Rosales', 'Padilla', 'Enriquez', 'Aguilar', 'Feliciano',
        'Gutierrez', 'Trinidad', 'Malabanan', 'Dimaculangan', 'Panganiban',
        'Hernandez', 'Lazaro', 'Buenaventura', 'Carandang', 'Andrada',
    ];

    private const MALE_NAMES = [
        'Juan', 'Jose', 'Ricardo', 'Antonio', 'Carlo', 'Miguel', 'Rafael', 'Diego',
        'Emilio', 'Noel', 'Ferdinand', 'Rodel', 'Arnel', 'Christian', 'Marlon',
        'Benigno', 'Danilo', 'Eduardo', 'Fidel', 'Gerardo', 'Hermogenes',
        'Ignacio', 'Jomar', 'Kevin', 'Leonardo', 'Mateo', 'Nestor', 'Orlando',
        'Paolo', 'Renato', 'Salvador', 'Teodoro', 'Vicente', 'Wilfredo',
    ];

    private const FEMALE_NAMES = [
        'Maria', 'Ana', 'Rosario', 'Luzviminda', 'Jocelyn', 'Cristina', 'Bernadette',
        'Aileen', 'Divina', 'Marissa', 'Grace', 'Teresita', 'Angelica', 'Rowena', 'Imelda',
        'Belen', 'Corazon', 'Dolores', 'Elena', 'Flordeliza', 'Gemma', 'Herminia',
        'Isabel', 'Josefina', 'Katrina', 'Liwayway', 'Milagros', 'Norma',
        'Perla', 'Remedios', 'Soledad', 'Trinidad', 'Veronica', 'Yolanda',
    ];

    public function run(): void
    {
        // Same seed, same data, every run.
        mt_srand(20260101);

        /* TWENTY shelters, not eight. Enough that the City Admin shelter list
           paginates, the dashboard charts have a real distribution to draw, and
           a transfer between two POPULATED shelters can be demonstrated without
           first having to register somebody. The remaining shelters stay empty
           on purpose -- a system where every shelter is occupied does not look
           like a real disaster, and the empty-state screens need to be
           reachable too. */
        $centers = EvacuationCenter::orderBy('id')->take(20)->get();
        if ($centers->isEmpty()) {
            return;
        }

        $codes = VulnerableClassification::pluck('id', 'code');
        $seq = 0;

        foreach ($centers as $index => $center) {
            // A different profile per shelter so the dashboards are not eight
            // copies of the same bar chart.
            /* Deliberately uneven. Two shelters are heavily loaded so the
               capacity thresholds go amber and red, most sit comfortably, and a
               couple hold only one or two households so a nearly-empty roster is
               also on screen somewhere. The first shelter carries enough
               households to force the roster to paginate. */
            $householdCount = [
                18, 14, 12, 11, 10, 9, 9, 8,
                8, 7, 7, 6, 6, 5, 5, 4, 4, 3, 2, 2,
            ][$index] ?? 4;

            for ($h = 0; $h < $householdCount; $h++) {
                $seq++;
                $this->makeHousehold($center, $codes, $seq, $index, $h);
            }
        }

        // Occupancy last, and by recalculation, never by arithmetic.
        foreach (EvacuationCenter::all() as $center) {
            $center->recalcOccupancy();
        }

        mt_srand();
    }

    private function makeHousehold(EvacuationCenter $center, $codes, int $seq, int $shelterIndex, int $h): void
    {
        $surname = self::SURNAMES[$seq % count(self::SURNAMES)];
        $size = [5, 4, 3, 6, 2, 1, 4, 3][$h % 8];

        /* Status mix. Most households are checked in -- that is the working
           state the whole system is built around -- but a few sit in the other
           states so every filter has more than one value to choose between. */
        $status = 'checked_in';
        if ($h === 2 && $shelterIndex % 3 === 0) {
            $status = 'registered';     // pre-registered, not yet arrived
        } elseif ($h === 4 && $shelterIndex % 4 === 1) {
            $status = 'checked_out';    // went home
        }

        // Only households declared separated get the flag, and only a couple of
        // shelters have any, so the panel shows both a populated and an empty state.
        $isSeparated = ($status === 'checked_in' && $size === 1 && $shelterIndex < 3);

        $household = Household::create([
            'household_code' => sprintf('HH-%d-%05d', now()->year, $seq),
            'origin_barangay_id' => $center->barangay_id,
            'evacuation_center_id' => $status === 'registered' ? null : $center->id,
            'origin_address' => $this->address($seq),
            'is_separated' => $isSeparated,
            'number_of_members' => $size,
            'members_present' => 0,
            'status' => $status,
            'checked_in_at' => $status === 'registered' ? null : now()->subDays(mt_rand(0, 4)),
            'checked_out_at' => $status === 'checked_out' ? now()->subDays(mt_rand(0, 2)) : null,
        ]);

        $members = $this->makeMembers($household, $surname, $size, $status, $seq, $codes);

        $head = $members->firstWhere('is_household_head', true) ?? $members->first();

        $household->update([
            'head_member_id' => $head?->id,
            'number_of_members' => $members->count(),
            'members_present' => $status === 'checked_in'
                ? $members->where('is_present', true)->count()
                : 0,
        ]);
    }

    private function makeMembers(Household $household, string $surname, int $size, string $status, int $seq, $codes)
    {
        /* Age plan per household size. Written out rather than randomised so
           every tier is guaranteed to appear somewhere in the data -- including
           Infant, which a purely random spread misses more often than not.

           'months' drives a real birthdate. 'fallback' means NO birthdate: the
           member carries an age_tier_fallback instead, exercising the other
           derivation path. */
        $plans = [
            1 => [['months' => 400, 'sex' => 'female']],
            2 => [['months' => 780, 'sex' => 'male'], ['months' => 760, 'sex' => 'female']],
            3 => [['months' => 380, 'sex' => 'male'], ['months' => 360, 'sex' => 'female'], ['months' => 3, 'sex' => 'female']],
            4 => [['months' => 420, 'sex' => 'female'], ['months' => 190, 'sex' => 'male'], ['months' => 100, 'sex' => 'female'], ['months' => 20, 'sex' => 'male']],
            5 => [['months' => 450, 'sex' => 'male'], ['months' => 430, 'sex' => 'female'], ['months' => 200, 'sex' => 'female'], ['months' => 90, 'sex' => 'male'], ['fallback' => AgeTier::INFANT, 'sex' => 'female']],
            6 => [['months' => 500, 'sex' => 'male'], ['months' => 480, 'sex' => 'female'], ['months' => 730, 'sex' => 'female'], ['months' => 210, 'sex' => 'male'], ['months' => 50, 'sex' => 'female'], ['fallback' => AgeTier::PRESCHOOL, 'sex' => 'male']],
        ];

        $plan = $plans[$size] ?? $plans[4];
        $rows = collect();

        foreach ($plan as $i => $spec) {
            $sex = $spec['sex'];
            $given = $sex === 'female'
                ? self::FEMALE_NAMES[($seq + $i) % count(self::FEMALE_NAMES)]
                : self::MALE_NAMES[($seq + $i) % count(self::MALE_NAMES)];

            /* Presence. Everyone is present except one person in roughly every
               fourth household, which is what gives presence correction and the
               did-not-arrive screens something to work on. Never the head, so
               the acting-head path is not triggered by accident here. */
            $present = $status === 'checked_in';
            if ($present && $i > 0 && $seq % 4 === 0 && $i === count($plan) - 1) {
                $present = false;
            }

            $member = HouseholdMember::create([
                'household_id' => $household->id,
                // "Last, First" -- the format the whole application splits on.
                'full_name' => $surname . ', ' . $given,
                'birthdate' => isset($spec['months'])
                    ? Carbon::now()->subMonths($spec['months'])->toDateString()
                    : null,
                'age_tier_fallback' => $spec['fallback'] ?? null,
                'sex' => $sex,
                'family_role' => $i === 0 ? 'Head' : ($i === 1 ? 'Spouse' : 'Child'),
                'is_household_head' => $i === 0,
                'is_present' => $present,
                'contact_number' => $i === 0 ? '09' . str_pad((string) (170000000 + $seq * 7), 9, '0', STR_PAD_LEFT) : null,
            ]);

            $this->tag($member, $codes, $seq, $i, $spec);
            $rows->push($member);
        }

        return $rows;
    }

    /**
     * Vulnerability tags.
     *
     * THE FEMALE-ONLY RULE IS ENFORCED HERE TOO. pregnant and lactating are
     * never applied to a non-female member. The application enforces this in
     * three places already; a seeder that ignored it would plant exactly the
     * rows applyTags() exists to strip, and the first edit of that family would
     * silently change the data.
     */
    private function tag(HouseholdMember $member, $codes, int $seq, int $i, array $spec): void
    {
        $wanted = [];
        $months = $spec['months'] ?? null;

        if ($months !== null && $months >= 720 && $seq % 3 === 0) {
            $wanted[] = 'pwd';
        }
        if ($member->sex === 'female' && $months !== null && $months >= 216 && $months < 600 && $seq % 5 === 0) {
            $wanted[] = 'pregnant';
        }
        if ($member->sex === 'female' && $months !== null && $months >= 216 && $months < 600 && $seq % 7 === 0) {
            $wanted[] = 'lactating';
        }
        if ($i === 0 && $seq % 6 === 0) {
            $wanted[] = 'solo_parent';
        }
        if ($i === 0 && $seq % 4 === 0) {
            $wanted[] = 'fourps';
        }
        if ($months !== null && $months >= 600 && $seq % 9 === 0) {
            $wanted[] = 'chronic_illness';
        }

        foreach ($wanted as $code) {
            if (! isset($codes[$code])) {
                continue;
            }
            // Belt and braces: never a female-only code on a non-female member.
            if (in_array($code, ['pregnant', 'lactating'], true) && $member->sex !== 'female') {
                continue;
            }

            MemberVulnerability::firstOrCreate([
                'household_member_id' => $member->id,
                'vulnerable_classification_id' => $codes[$code],
            ], [
                // No authenticated user in a console context, so this stays null
                // rather than pretending somebody tagged it.
                'tagged_by' => null,
                'tagged_at' => now(),
            ]);
        }
    }

    private function address(int $seq): string
    {
        $puroks = ['Purok 1', 'Purok 2', 'Purok 3', 'Purok 4', 'Sitio Ibaba', 'Sitio Riverside'];

        return ($seq * 3 % 90 + 1) . ' ' . $puroks[$seq % count($puroks)];
    }
}
