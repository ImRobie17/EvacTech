<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_vulnerabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_member_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vulnerable_classification_id')->constrained()->restrictOnDelete();
            $table->text('details')->nullable(); // e.g. specific disability details
            $table->foreignId('tagged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('tagged_at')->nullable();
            $table->timestamps();

            $table->unique(['household_member_id', 'vulnerable_classification_id'], 'member_vuln_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_vulnerabilities');
    }
};
