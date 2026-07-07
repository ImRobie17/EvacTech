<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('evacuation_center_id')->constrained()->cascadeOnDelete();
            $table->date('log_date');
            $table->unsignedInteger('members_present')->default(0);
            $table->enum('status', ['present', 'absent', 'partial'])->default('present');
            $table->foreignId('logged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['household_id', 'log_date'], 'attendance_household_date_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_logs');
    }
};
