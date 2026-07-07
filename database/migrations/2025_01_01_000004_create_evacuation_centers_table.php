<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evacuation_centers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('barangay_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('address');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedInteger('capacity')->default(0);
            $table->unsignedInteger('current_occupancy')->default(0);
            $table->boolean('has_water_supply')->default(false);
            $table->boolean('has_medical_desk')->default(false);
            $table->boolean('has_power')->default(false);
            $table->boolean('has_communal_kitchen')->default(false);
            $table->enum('status', ['active', 'inactive', 'full'])->default('active');
            $table->foreignId('managed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evacuation_centers');
    }
};
