<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('relief_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evacuation_center_id')->constrained()->cascadeOnDelete();
            $table->foreignId('relief_good_id')->constrained()->restrictOnDelete();
            $table->enum('type', ['received', 'distributed', 'allocated_in']);
            $table->unsignedInteger('quantity');
            $table->foreignId('household_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_or_recipient')->nullable(); // donor name, city warehouse, etc.
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('transaction_date');
            $table->text('remarks')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('relief_transactions');
    }
};
