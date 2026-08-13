<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * SEPARATED HOUSEHOLDS, DROP 1 -- the DECLARATION.
 *
 * WHY A DECLARED FLAG RATHER THAN DETECTION
 * -----------------------------------------
 * The previous design inferred separation by comparing names across shelters.
 * It could not work. Exact matching missed "Maria Santos" against "Maria Santos
 * Jr."; loosening it would have proposed merging strangers who happen to share
 * a surname, and the whole point of the feature is that it proposes merging two
 * people's records. There is no threshold that is both safe and useful.
 *
 * So the system stops guessing. The person at the desk says "my family is
 * sheltering somewhere else", and the operator records that as a fact. A human
 * statement about their own family beats any string comparison, and it is
 * available at exactly the moment somebody is standing there to be asked.
 *
 * ON THE HOUSEHOLD, NOT THE MEMBER. More than one person can be separated
 * together -- a mother and child who were at market when the water rose are one
 * household here and part of a larger family elsewhere. A member-level flag
 * would need every row ticked and would still leave the household itself
 * unmarked on every roster and list.
 *
 * DELIBERATELY NOT A LINK. This says only "this household belongs to a family
 * elsewhere", not which one. Naming the family is optional, happens at arrival
 * when both are in the same building, and is Drop 2. A nullable FK here would
 * be null for the overwhelming majority of the feature's life.
 *
 * NO COUNTING CHANGE. A separated household counts as its own affected family
 * at its own shelter, and city-wide totals do not deduplicate. That is the
 * client's decision and it keeps the Single Headed Household rule -- which
 * exists in five hand-written copies, one of them raw SQL -- entirely untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('households', function (Blueprint $table) {
            $table->boolean('is_separated')
                ->default(false)
                ->after('origin_address');
        });
    }

    public function down(): void
    {
        Schema::table('households', function (Blueprint $table) {
            $table->dropColumn('is_separated');
        });
    }
};
