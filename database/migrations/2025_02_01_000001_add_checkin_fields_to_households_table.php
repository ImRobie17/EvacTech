<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('households', function (Blueprint $table) {
            $table->timestamp('checked_in_at')->nullable()->after('status');
            $table->timestamp('checked_out_at')->nullable()->after('checked_in_at');
            $table->unsignedInteger('members_present')->default(0)->after('number_of_members');
        });
    }

    public function down(): void
    {
        Schema::table('households', function (Blueprint $table) {
            $table->dropColumn(['checked_in_at', 'checked_out_at', 'members_present']);
        });
    }
};
