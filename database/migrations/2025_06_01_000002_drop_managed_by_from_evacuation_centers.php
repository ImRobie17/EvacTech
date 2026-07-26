<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `managed_by` held a single user id, which cannot represent a rotating roster.
 * Shelter staffing now lives entirely in the evacuation_center_user pivot, so the
 * column is removed to keep one source of truth (it was already backfilled into
 * the pivot by the previous migration -- run these in order).
 *
 * Also normalises status 'full' -> 'active'. Overcapacity is now DERIVED from
 * occupancy vs capacity (see EvacuationCenter::capacityBand) and never changes
 * the operational status: an overfull shelter must keep accepting and tracking
 * evacuees.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('evacuation_centers')->where('status', 'full')->update(['status' => 'active']);

        if (Schema::hasColumn('evacuation_centers', 'managed_by')) {
            Schema::table('evacuation_centers', function (Blueprint $table) {
                // SQLite has no real FK to drop and throws if asked.
                if (DB::getDriverName() !== 'sqlite') {
                    $table->dropForeign(['managed_by']);
                }
                $table->dropColumn('managed_by');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('evacuation_centers', 'managed_by')) {
            Schema::table('evacuation_centers', function (Blueprint $table) {
                $table->foreignId('managed_by')->nullable()->after('status')
                    ->constrained('users')->nullOnDelete();
            });
        }
    }
};
