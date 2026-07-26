<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_alerts', function (Blueprint $table) {
            $table->id();
            $table->enum('level', ['info', 'warning', 'error', 'critical'])->default('error');
            $table->enum('type', ['database', 'system', 'backup', 'maintenance', 'auth', 'other'])->default('system');
            $table->string('title');
            $table->text('message')->nullable();
            $table->string('source')->nullable();       // e.g. exception class, controller
            $table->boolean('email_sent')->default(false);
            $table->boolean('is_resolved')->default(false);
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['level', 'is_resolved']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_alerts');
    }
};
