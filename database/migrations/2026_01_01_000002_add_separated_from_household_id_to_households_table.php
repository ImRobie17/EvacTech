<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * PHASE 10A -- the CONFIRMED link, and the reason the counting changes are one
 * line each rather than a join everywhere.
 *
 * WHICH END CARRIES IT. The FRAGMENT household -- the one holding the person
 * who was elsewhere when the disaster struck -- points at the FAMILY household.
 * Not the other way round, and not both. A family can have more than one member
 * scattered, so the family end would need a one-to-many; the fragment end is
 * always one-to-one, because a fragment household exists precisely because its
 * occupant belongs to exactly one family elsewhere.
 *
 * WHAT IT BUYS
 * ------------
 * 1. City-wide family totals dedupe with `whereNull('separated_from_household_id')`.
 *    A + B is one family, counted at the family end. PER-SHELTER counts are
 *    deliberately NOT changed: shelter B really is sheltering a family fragment
 *    and its own affected-families figure must say so.
 * 2. Single Headed Household excludes fragments with the same clause. A lone
 *    separated person is a piece of a larger family, not a one-person
 *    household, and flagging her single-headed puts a false figure on a signed
 *    CSWDO form.
 *
 * nullOnDelete, not cascade: deleting the family household must not delete the
 * fragment household and the live person inside it. The fragment simply stops
 * being linked and goes back to counting as its own family, which is the
 * correct reading once the other record is gone.
 *
 * NOT a member-level shelter column. The invariant holds: a household lives at
 * exactly one shelter and a person belongs to exactly one household. This
 * column links two HOUSEHOLD rows; it never lets a member live in two places.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('households', function (Blueprint $table) {
            $table->foreignId('separated_from_household_id')
                ->nullable()
                ->after('evacuation_center_id')
                ->constrained('households')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('households', function (Blueprint $table) {
            $table->dropForeign(['separated_from_household_id']);
            $table->dropColumn('separated_from_household_id');
        });
    }
};
