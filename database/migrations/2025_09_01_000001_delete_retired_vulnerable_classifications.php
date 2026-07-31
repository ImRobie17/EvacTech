<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PHASE 3 ITEM 11b -- remove the two retired vulnerable classifications.
 *
 * Senior Citizen and Infant / Young Child predate the age tiers. Phase 2 turned
 * both into DERIVED tiers and retired the classification rows with
 * is_selectable = false rather than deleting them, so that any member already
 * tagged kept their history.
 *
 * That history turned out to be empty. Verified before writing this migration:
 *
 *   SELECT vc.code, COUNT(mv.id) AS tagged_rows
 *   FROM vulnerable_classifications vc
 *   LEFT JOIN member_vulnerabilities mv
 *          ON mv.vulnerable_classification_id = vc.id
 *   WHERE vc.code IN ('senior_citizen', 'infant_young_child')
 *   GROUP BY vc.code;
 *
 * returned 0 for both. With nothing to preserve, the rows are pure confusion in
 * the raw table -- two categories that no dropdown offers and no count includes,
 * sitting beside six that are live. They go.
 *
 * NOTE: every UI path already filters on ->selectable(), so nothing visible
 * changes. This is a data-hygiene change, not a behaviour change.
 *
 * The matching entries are removed from VulnerableClassificationSeeder in the
 * same commit. Without that edit `php artisan db:seed` would resurrect both rows
 * via updateOrCreate() the next time the database is reset -- which, before a
 * defence, it will be.
 */
return new class extends Migration
{
    private const RETIRED = ['senior_citizen', 'infant_young_child'];

    public function up(): void
    {
        $ids = DB::table('vulnerable_classifications')
            ->whereIn('code', self::RETIRED)
            ->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        // member_vulnerabilities.vulnerable_classification_id is restrictOnDelete,
        // so a single surviving pivot row would abort the delete below with
        // MySQL error 1451. The count was verified at zero, but this migration
        // may run on a database that has been used since, so clear defensively
        // rather than failing halfway.
        DB::table('member_vulnerabilities')
            ->whereIn('vulnerable_classification_id', $ids)
            ->delete();

        DB::table('vulnerable_classifications')
            ->whereIn('code', self::RETIRED)
            ->delete();
    }

    /**
     * Restores the two rows in their retired state.
     *
     * Honest about its limits: this brings back the CLASSIFICATIONS, not who was
     * tagged with them. On this database that costs nothing, because nobody was.
     */
    public function down(): void
    {
        $now = now();

        $rows = [
            [
                'code' => 'senior_citizen',
                'name' => 'Senior Citizen',
                'description' => 'Retired -- now derived as an age tier',
                'is_selectable' => false,
            ],
            [
                'code' => 'infant_young_child',
                'name' => 'Infant / Young Child',
                'description' => 'Retired -- now derived as an age tier',
                'is_selectable' => false,
            ],
        ];

        foreach ($rows as $row) {
            $exists = DB::table('vulnerable_classifications')
                ->where('code', $row['code'])
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('vulnerable_classifications')->insert($row + [
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
};
