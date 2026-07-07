<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('household_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_center_id')->nullable()->constrained('evacuation_centers')->nullOnDelete();
            $table->foreignId('to_center_id')->constrained('evacuation_centers')->restrictOnDelete();
            $table->foreignId('new_head_member_id')->nullable()->constrained('household_members')->nullOnDelete();
            $table->string('reason')->nullable();
            $table->foreignId('transferred_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('transferred_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('household_transfers');
    }
};
