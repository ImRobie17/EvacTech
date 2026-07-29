<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Phase 2 item 6.
 *
 * Two columns, and deliberately NO deletes.
 *
 * 'code' is a stable slug. All logic keys on it, never on the display name.
 * This kills the fragile where('name','like','Senior%') matching that the old
 * syncMembers() used, and means the IDP Monitoring Form whitelist in Phase 3
 * cannot break when somebody edits a label to match the official wording.
 *
 * 'is_selectable' retires a classification without destroying data. The FK on
 * member_vulnerabilities is restrictOnDelete, so a hard delete would fail while
 * tagged rows exist -- and those rows are real history. Senior Citizen and
 * Infant / Young Child become age tiers, so they are retired here: gone from
 * every dropdown and every count, but their rows survive.
 *
 * Person with Chronic Illness stays SELECTABLE. It is operationally useful as
 * an internal medical-desk tag. It is simply absent from the IDP form's list of
 * five, which is an explicit whitelist in the report builder, not a query over
 * this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vulnerable_classifications', function (Blueprint $table) {
            $table->string('code', 30)->nullable()->unique()->after('id');
            $table->boolean('is_selectable')->default(true)->after('description');
        });

        // Backfill codes onto the rows the original seeder created, so existing
        // tagged members keep working. Matched on the exact seeded names.
        $map = [
            'Person with Disability (PWD)' => 'pwd',
            'Senior Citizen' => 'senior_citizen',
            'Pregnant Woman' => 'pregnant',
            'Infant / Young Child' => 'infant_young_child',
            'Person with Chronic Illness' => 'chronic_illness',
            'Solo Parent' => 'solo_parent',
        ];

        foreach ($map as $name => $code) {
            DB::table('vulnerable_classifications')
                ->where('name', $name)
                ->whereNull('code')
                ->update(['code' => $code]);
        }

        // Retire the two that became age tiers. History is preserved.
        DB::table('vulnerable_classifications')
            ->whereIn('code', ['senior_citizen', 'infant_young_child'])
            ->update(['is_selectable' => false]);
    }

    public function down(): void
    {
        Schema::table('vulnerable_classifications', function (Blueprint $table) {
            $table->dropColumn(['code', 'is_selectable']);
        });
    }
};
