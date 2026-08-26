<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 5 ITEM 8b -- who did not arrive on a transfer, and why.
 *
 * Before this, shelter_transfers stored member information only as two counts:
 * members_expected and members_received. The difference between them was a
 * number with no names attached, which is exactly the thing a CSWDO officer
 * would be asked about first.
 *
 * WHY JSON RATHER THAN A CHILD TABLE: the volume is tiny -- a handful of people
 * on a handful of transfers -- and keeping it here means the complete record of
 * one movement stays on one row. If reporting ever needs to aggregate absences
 * across many transfers, promote it to a shelter_transfer_members table then.
 * Do not pre-build that now.
 *
 * SHAPE: a list of objects, each
 *   {"member_id": n, "reason": "code", "resolved": null, "resolved_by": null,
 *    "resolved_at": null}
 *
 * Nothing DERIVED is stored in it. No names -- those come from
 * household_members, so a corrected spelling is corrected everywhere. No
 * "unaccounted" flag -- that is computed on read from the reason, the member's
 * is_present and the household's status, so it clears itself the moment the
 * person is marked present and can never go stale.
 *
 * NULLABLE, so every existing completed transfer stays valid and simply has no
 * absence record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shelter_transfers', function (Blueprint $table) {
            $table->json('did_not_arrive')->nullable()->after('members_received');
        });
    }

    public function down(): void
    {
        Schema::table('shelter_transfers', function (Blueprint $table) {
            $table->dropColumn('did_not_arrive');
        });
    }
};
