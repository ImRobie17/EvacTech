<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('barangays', function (Blueprint $table) {
            $table->string('office_contact_number', 20)->nullable()->after('longitude');
            $table->string('office_contact_email')->nullable()->after('office_contact_number');
        });
    }

    public function down(): void
    {
        Schema::table('barangays', function (Blueprint $table) {
            $table->dropColumn(['office_contact_number', 'office_contact_email']);
        });
    }
};
