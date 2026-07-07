<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('household_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->string('full_name');
            $table->unsignedTinyInteger('age')->nullable();
            $table->date('birthdate')->nullable();
            $table->enum('sex', ['male', 'female'])->nullable();
            $table->string('family_role')->nullable(); // e.g. head, spouse, child, dependent
            $table->boolean('is_household_head')->default(false);
            $table->string('contact_number', 20)->nullable();
            $table->timestamps();
        });

        Schema::table('households', function (Blueprint $table) {
            $table->foreign('head_member_id')->references('id')->on('household_members')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('households', function (Blueprint $table) {
            $table->dropForeign(['head_member_id']);
        });
        Schema::dropIfExists('household_members');
    }
};
