<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emergency_hotlines', function (Blueprint $table) {
            $table->id();
            $table->string('label');                 // e.g. "CDRRMO Operations Center"
            $table->string('number');                // e.g. "(049) 123-4567" or "0917-000-0000"
            $table->enum('category', ['disaster', 'police', 'fire', 'medical', 'utilities', 'other'])->default('other');
            $table->string('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emergency_hotlines');
    }
};
