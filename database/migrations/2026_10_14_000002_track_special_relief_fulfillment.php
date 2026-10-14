<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('special_relief_requests', function (Blueprint $table) {
            // Special items bypass shelter inventory. These two counters track
            // only goods physically received for, and handed to, the family.
            $table->unsignedInteger('received_quantity')->default(0);
            $table->unsignedInteger('distributed_quantity')->default(0);
        });

        Schema::create('special_relief_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('special_relief_request_id')->constrained('special_relief_requests')->cascadeOnDelete();
            $table->enum('action', ['received', 'distributed']);
            $table->unsignedInteger('quantity');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('submission_token', 36)->unique();
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->index(['special_relief_request_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('special_relief_movements');
        Schema::table('special_relief_requests', function (Blueprint $table) {
            $table->dropColumn(['received_quantity', 'distributed_quantity']);
        });
    }
};
