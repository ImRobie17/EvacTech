<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 2 ITEM 8 -- shelter-to-shelter transfers.
 *
 * DELIBERATELY NOT an extension of `household_transfers`. That table records
 * family-HEAD-role changes, where from_center_id == to_center_id and the only
 * meaningful column is new_head_member_id. It has no status, no lifecycle and
 * no second actor. Bolting six timestamps and a state machine onto it would
 * have left every existing head-transfer row in an undefined state and forced
 * every query to filter on "rows where the two centre ids differ".
 *
 * Shelter moves get their own table. household_transfers and
 * ShelterController::transferHead() are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shelter_transfers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_center_id')->constrained('evacuation_centers')->restrictOnDelete();
            $table->foreignId('to_center_id')->constrained('evacuation_centers')->restrictOnDelete();

            // pending -> approved -> in_transit -> completed, plus the two exits.
            $table->enum('status', [
                'pending', 'approved', 'in_transit', 'completed', 'refused', 'cancelled',
            ])->default('pending');

            $table->string('reason')->nullable();

            /**
             * Snapshot of households.checked_in_at as it stood when this transfer
             * was raised, i.e. when the family arrived at the ORIGIN shelter.
             *
             * On receipt the household's own checked_in_at is reset to the
             * arrival time at the destination, so every screen reports "checked
             * in since" for the shelter the family is actually in. The original
             * time is not lost: it lives here, once per hop, so the full stay
             * reconstructs from the transfer chain. A single first_checked_in_at
             * column on households could only ever remember one hop, and would
             * need a rule for what happens when a family checks out and returns.
             */
            $table->timestamp('origin_checked_in_at')->nullable();

            // Headcount leaving vs headcount that actually walked in. They differ
            // whenever someone peels off on the way, which is why receipt asks
            // staff to tick arrivals rather than assuming.
            $table->unsignedInteger('members_expected')->default(0);
            $table->unsignedInteger('members_received')->nullable();

            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at')->nullable();

            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();

            // departed_at is the OUT time the roadmap asks for.
            $table->foreignId('departed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('departed_at')->nullable();

            // received_at is the IN time.
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable();

            $table->foreignId('refused_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('refused_at')->nullable();
            $table->string('refusal_reason')->nullable();

            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();

            $table->timestamps();

            // The overdue query is WHERE status = 'in_transit' AND departed_at <= ?
            // and it runs on every page load for staff and City Admin, so it gets
            // its own composite index.
            $table->index(['status', 'departed_at']);

            // The alert bar and both Transfers pages filter by centre and status.
            $table->index(['to_center_id', 'status']);
            $table->index(['from_center_id', 'status']);

            // "Does this household already have an open transfer?" -- checked
            // before every create and before every check-out.
            $table->index(['household_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shelter_transfers');
    }
};
