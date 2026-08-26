<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 item 5.
 *
 * Age tier is DERIVED from birthdate on every read, never stored -- a stored
 * tier goes stale during a long stay. This column is NOT the tier. It is the
 * fallback used ONLY when birthdate is null, for the fast "tag them now, get
 * the birthday later" registration path.
 *
 * Precedence is absolute and enforced in AgeTier::forMember(): if birthdate is
 * present, this column is ignored entirely, and syncMembers() nulls it out so
 * the two can never drift apart in the database either.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('household_members', function (Blueprint $table) {
            $table->string('age_tier_fallback', 20)->nullable()->after('birthdate');
        });
    }

    public function down(): void
    {
        Schema::table('household_members', function (Blueprint $table) {
            $table->dropColumn('age_tier_fallback');
        });
    }
};
