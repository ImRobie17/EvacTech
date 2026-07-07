<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('households', function (Blueprint $table) {
            $table->id();
            $table->string('household_code')->unique(); // e.g. HH-2026-00001
            $table->foreignId('origin_barangay_id')->constrained('barangays')->restrictOnDelete();
            $table->foreignId('evacuation_center_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('head_member_id')->nullable(); // FK attached after household_members exists
            $table->string('origin_address')->nullable();
            $table->unsignedInteger('number_of_members')->default(0);
            $table->enum('status', ['registered', 'checked_in', 'transferred', 'checked_out'])->default('registered');
            $table->foreignId('registered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('households');
    }
};
