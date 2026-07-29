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
 * NOTHING IS DELETED HERE. Senior Citizen and Infant / Young Child became age
 * tiers, so they are marked is_selectable = false: invisible in every dropdown
 * and excluded from every count, with their historical rows intact. The FK on
 * member_vulnerabilities is restrictOnDelete, so a hard delete would fail while
 * those rows exist anyway.
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

            // Retired: these are age tiers now. Rows preserved, never counted.
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

        foreach ($classifications as $item) {
            VulnerableClassification::updateOrCreate(['code' => $item['code']], $item);
        }
    }
}
