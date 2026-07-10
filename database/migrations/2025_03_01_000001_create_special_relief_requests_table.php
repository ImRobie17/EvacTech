<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('special_relief_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('evacuation_center_id')->constrained()->cascadeOnDelete();
            $table->string('item_description');           // "Diapers (newborn)", "Maintenance meds - hypertension", "Wheelchair"
            $table->unsignedInteger('quantity')->default(1);
            $table->enum('status', ['pending', 'approved', 'rejected', 'fulfilled'])->default('pending');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('remarks')->nullable();          // reason for the request, or reviewer note
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('special_relief_requests');
    }
};
