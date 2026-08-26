<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 9 ITEM 2 -- the temporary (acting) household head.
 *
 * WHY A SEPARATE COLUMN, AND WHY head_member_id IS NOT TOUCHED.
 *
 * Two facts about a household's head already live in two places and are kept in
 * sync by exactly two write paths: HouseholdMemberSync::sync() and
 * Barangay\ShelterController::transferHead(). Roughly fourteen search and sort
 * sites key on household_members.is_household_head, two of them ORDER BY
 * subqueries that become non-deterministic the moment more than one member of a
 * family carries the flag.
 *
 * So an acting head does NOT reuse either of them. head_member_id and
 * is_household_head both keep pointing at the SUBSTANTIVE head -- the family's
 * real head, the one a CSWDO officer would name -- and this column records, in
 * addition, who is answerable for the family at the shelter while that person is
 * not present. Nothing that already reads the head changes meaning, which is
 * what makes this affordable and what keeps Phase 10A's assumption (that head
 * designation is reliable) true.
 *
 * LIFECYCLE: set at check-in when the substantive head is not among the people
 * ticked present; cleared when staff choose to revert on the Update Presence
 * screen; cleared automatically at check-out. It never survives a stay.
 *
 * NOT the same feature as transferHead(). That permanently changes who the head
 * IS and writes a household_transfers row. This is a stand-in and writes
 * nothing but this column. Neither is a shortcut for the other.
 *
 * nullOnDelete rather than cascade: deleting the stand-in's member row should
 * drop the household back to "no acting head" -- which reads as the substantive
 * head -- not delete the family.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('households', function (Blueprint $table) {
            $table->foreignId('acting_head_member_id')
                ->nullable()
                ->after('head_member_id')
                ->constrained('household_members')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('households', function (Blueprint $table) {
            $table->dropForeign(['acting_head_member_id']);
            $table->dropColumn('acting_head_member_id');
        });
    }
};
