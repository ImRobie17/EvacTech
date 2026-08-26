<?php

namespace Database\Seeders;

use App\Models\VulnerableClassification;
use Illuminate\Database\Seeder;

/**
 * Phase 2 item 6 -- the revised category list.
 *
 * Matched on 'code', never on 'name', so the display label can be edited to
 * match the official CSWDO wording without orphaning a single tagged row.
 *
 * PHASE 3 ITEM 11b -- Senior Citizen and Infant / Young Child are GONE.
 *
 * Phase 2 retired them with is_selectable = false when they became derived age
 * tiers, keeping the rows so that anyone already tagged kept their history. A
 * check before removal found that nobody was: zero rows in
 * member_vulnerabilities pointed at either. With no history to preserve they
 * were only confusing the raw table, so migration
 * 2025_09_01_000001_delete_retired_vulnerable_classifications deletes them.
 *
 * THEY MUST NOT COME BACK HERE. updateOrCreate() below matches on 'code', so
 * re-adding either entry would recreate the row on the next db:seed and quietly
 * undo that migration.
 *
 * Person with Chronic Illness stays SELECTABLE on purpose. It does not appear
 * on the IDP Monitoring Form, but it is operationally useful to the medical
 * desk. The form is driven by an explicit whitelist of five codes in the report
 * builder, not by a query over this table, so keeping this tag costs the form
 * nothing.
 */
class VulnerableClassificationSeeder extends Seeder
{
    /** The five that appear in table 2 of the IDP Monitoring Form. */
    public const REPORTABLE = ['pwd', 'pregnant', 'lactating', 'solo_parent', 'fourps'];

    public function run(): void
    {
        $classifications = [
            [
                'code' => 'pwd',
                'name' => 'Person with Disability (PWD)',
                'description' => 'Individuals with physical, sensory, or cognitive disabilities',
                'is_selectable' => true,
            ],
            [
                'code' => 'pregnant',
                'name' => 'Pregnant Woman',
                'description' => 'Currently pregnant',
                'is_selectable' => true,
            ],
            [
                'code' => 'lactating',
                'name' => 'Lactating Mother',
                'description' => 'Currently breastfeeding an infant',
                'is_selectable' => true,
            ],
            [
                'code' => 'solo_parent',
                'name' => 'Solo Parent',
                'description' => 'Sole caregiver of dependents',
                'is_selectable' => true,
            ],
            [
                'code' => 'fourps',
                'name' => '4Ps Beneficiary',
                'description' => 'Pantawid Pamilyang Pilipino Program beneficiary',
                'is_selectable' => true,
            ],

            // Kept, selectable, but NOT on the official form. Internal use.
            [
                'code' => 'chronic_illness',
                'name' => 'Person with Chronic Illness',
                'description' => 'Requires ongoing medical attention or medication (internal tag, not reported on the IDP form)',
                'is_selectable' => true,
            ],
        ];

        foreach ($classifications as $item) {
            VulnerableClassification::updateOrCreate(['code' => $item['code']], $item);
        }
    }
}
