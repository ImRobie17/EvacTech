<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('relief_goods', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('unit'); // kg, pack, liter, box, piece
            $table->enum('category', ['food', 'water', 'hygiene', 'medical', 'shelter', 'other'])->default('other');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('relief_goods');
    }
};
