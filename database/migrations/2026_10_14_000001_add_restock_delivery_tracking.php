<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('relief_allocation_requests', function (Blueprint $table) {
            $table->uuid('request_group_id')->nullable()->index();
            // Custom requests are not catalogue entries until City Admin approves.
            $table->string('custom_good_name', 100)->nullable();
            $table->text('custom_description')->nullable();
            $table->unsignedInteger('received_quantity')->default(0);
            $table->string('donor_type', 20)->nullable();
            $table->string('donor_name')->nullable();
            $table->decimal('monetary_value', 12, 2)->nullable();
            $table->timestamp('delivery_closed_at')->nullable();
            $table->foreignId('delivery_closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('delivery_closure_reason', 500)->nullable();
        });
        Schema::table('relief_allocation_requests', function (Blueprint $table) {
            $table->foreignId('relief_good_id')->nullable()->change();
        });
        Schema::table('relief_transactions', function (Blueprint $table) {
            $table->foreignId('relief_allocation_request_id')->nullable()
                ->constrained('relief_allocation_requests')->nullOnDelete();
        });

        // Legacy approvals already added the full quantity to inventory before
        // this migration. Mark them received to prevent a second stock increase.
        DB::table('relief_allocation_requests')
            ->whereIn('status', ['approved', 'fulfilled'])
            ->update(['received_quantity' => DB::raw('requested_quantity')]);
    }

    public function down(): void
    {
        Schema::table('relief_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('relief_allocation_request_id');
        });
        // Explicitly reject rollback if any custom requests have no catalogue item.
        // Those rows cannot fit the original non-null foreign key.
        if (DB::table('relief_allocation_requests')->whereNull('relief_good_id')->exists()) {
            throw new \RuntimeException('Resolve or export custom relief requests before rolling back this migration.');
        }
        Schema::table('relief_allocation_requests', function (Blueprint $table) {
            $table->foreignId('relief_good_id')->nullable(false)->change();
        });
        Schema::table('relief_allocation_requests', function (Blueprint $table) {
            $table->dropForeign(['delivery_closed_by']);
            $table->dropIndex(['request_group_id']);
            $table->dropColumn([
                'request_group_id', 'custom_good_name', 'custom_description',
                'received_quantity', 'donor_type', 'donor_name', 'monetary_value',
                'delivery_closed_at', 'delivery_closed_by', 'delivery_closure_reason',
            ]);
        });
    }
};
