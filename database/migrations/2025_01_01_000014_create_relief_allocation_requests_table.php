<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('relief_allocation_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evacuation_center_id')->constrained()->cascadeOnDelete();
            $table->foreignId('relief_good_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('requested_quantity');
            $table->enum('status', ['pending', 'approved', 'rejected', 'fulfilled'])->default('pending');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('relief_allocation_requests');
    }
};
