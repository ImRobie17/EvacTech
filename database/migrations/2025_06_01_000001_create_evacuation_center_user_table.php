<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 1 ITEM 1 -- one barangay now has MANY shelters, and staff are assigned
 * to specific shelters rather than inheriting access from their barangay.
 *
 * This pivot is the single source of truth for "may this staff member operate
 * this shelter?". There is deliberately NO lead / primary flag and NO shift
 * column: every assigned staff member has identical rights over the shelter,
 * always (staff rotate across three shifts and overtime during bad events, so
 * gating access by shift would lock people out mid-disaster).
 *
 * The backfill preserves existing behaviour exactly: every barangay personnel
 * account keeps access to every shelter in the barangay they were tied to, plus
 * any shelter they were previously set as `managed_by`. No migrate:fresh needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evacuation_center_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evacuation_center_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamps();

            $table->unique(['evacuation_center_id', 'user_id']);
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::dropIfExists('evacuation_center_user');
    }

    /**
     * Backfill from the two things that used to imply shelter access:
     *   1. users.barangay_id  -> every shelter in that barangay
     *   2. evacuation_centers.managed_by -> that one shelter
     * Runs before the managed_by column is dropped in the next migration.
     */
    private function backfill(): void
    {
        $now = now();
        $rows = [];

        // ---- 1. barangay_id implied access ----
        $staff = DB::table('users')
            ->join('roles', 'users.role_id', '=', 'roles.id')
            ->where('roles.name', 'barangay_personnel')
            ->whereNull('users.deleted_at')
            ->whereNotNull('users.barangay_id')
            ->select('users.id as user_id', 'users.barangay_id')
            ->get();

        $centersByBarangay = DB::table('evacuation_centers')
            ->select('id', 'barangay_id')
            ->get()
            ->groupBy('barangay_id');

        foreach ($staff as $member) {
            foreach ($centersByBarangay->get($member->barangay_id, collect()) as $center) {
                $rows["{$center->id}-{$member->user_id}"] = [
                    'evacuation_center_id' => $center->id,
                    'user_id' => $member->user_id,
                    'assigned_by' => null,
                    'assigned_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        // ---- 2. managed_by implied access (may cross barangays) ----
        if (Schema::hasColumn('evacuation_centers', 'managed_by')) {
            $managed = DB::table('evacuation_centers')
                ->whereNotNull('managed_by')
                ->select('id', 'managed_by')
                ->get();

            $validUserIds = DB::table('users')->whereNull('deleted_at')->pluck('id')->all();

            foreach ($managed as $center) {
                if (! in_array($center->managed_by, $validUserIds)) {
                    continue;
                }
                $rows["{$center->id}-{$center->managed_by}"] = [
                    'evacuation_center_id' => $center->id,
                    'user_id' => $center->managed_by,
                    'assigned_by' => null,
                    'assigned_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk(array_values($rows), 200) as $chunk) {
            DB::table('evacuation_center_user')->insert($chunk);
        }
    }
};
