<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * PHASE 10A -- separated family members: the WORKFLOW record.
 *
 * WHY A TABLE AND NOT COLUMNS ON households
 * -----------------------------------------
 * The plan names only households.separated_from_household_id, which is the
 * CONFIRMED link. A flagged-but-unconfirmed suggestion has nowhere to sit in
 * that design, and it needs six facts: which member row at each end, which
 * household at each end, who flagged it, who confirmed it and when. Six
 * nullable columns on an already-wide households row, five of them null for
 * every household in the city, is worse than one narrow table.
 *
 * A table also lets a REJECTED flag stay on file. Two people with the same name
 * really do turn up at two shelters, and when City Admin rules that out we want
 * the ruling recorded rather than the suggestion silently reappearing on the
 * barangay screen the next time the page loads.
 *
 * households.separated_from_household_id still exists (next migration) and is
 * set on CONFIRMATION ONLY. It is the denormalised READ path: city-wide family
 * dedup and the Single Headed Household exclusion become one WHERE clause each
 * instead of a join through this table at every counting site.
 *
 * NAMING. "family" is the household the person belongs to on paper; "present"
 * is the shelter where they physically are. On confirmation the FAMILY member
 * row is MOVED to the present household and the present (thin, hastily typed)
 * row is DELETED -- direction (b). member_vulnerabilities keys on
 * household_member_id, so the family row's tags follow the move for free; they
 * would have been cascade-deleted under the other direction.
 *
 * cascadeOnDelete on both member FKs: if either member row is deleted the
 * suggestion is meaningless and must not linger in the queue offering a button
 * that would fatal on a null relation (gotcha 13).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('separated_member_links', function (Blueprint $table) {
            $table->id();

            // The original row inside the family. MOVED on confirmation.
            $table->foreignId('family_member_id')
                ->constrained('household_members')
                ->cascadeOnDelete();
            $table->foreignId('family_household_id')
                ->constrained('households')
                ->cascadeOnDelete();

            // The row at the shelter where the person physically is. DELETED on
            // confirmation, after its blanks have been folded into the row above.
            $table->foreignId('present_member_id')
                ->constrained('household_members')
                ->cascadeOnDelete();
            $table->foreignId('present_household_id')
                ->constrained('households')
                ->cascadeOnDelete();

            $table->enum('status', ['pending', 'confirmed', 'rejected'])
                ->default('pending')
                ->index();

            $table->foreignId('flagged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('note')->nullable();

            $table->timestamps();

            // One suggestion per pair of member rows, whatever its outcome. A
            // rejected link therefore also blocks the same pair being flagged
            // again, which is the point -- see the note above.
            $table->unique(['family_member_id', 'present_member_id'], 'sep_link_pair_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('separated_member_links');
    }
};
