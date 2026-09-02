<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DROP B1 -- donor and monetary tracking on relief stock-in.
 *
 * All three columns are nullable AT THE DATABASE LEVEL because only stock-in
 * rows ever carry them: a `distributed` row has no donor and no value, and it
 * is written by the same table. donor_type is made mandatory by the RECEIVE
 * VALIDATION RULES, not by the schema -- see RecordsReliefReceipt::
 * reliefReceiptRules().
 *
 * donor_type is a plain string rather than a database enum, deliberately.
 * Adding a fifth donor category later then costs no migration, and the project
 * already keys logic on codes rather than labels everywhere else. The allowed
 * values live in ReliefTransaction::DONOR_TYPES and are enforced with
 * `in:lgu,dswd,ngo,other`.
 *
 * donor_name is its OWN column and does not reuse source_or_recipient. That
 * column already holds unrelated text on allocated_in rows ("City warehouse
 * (approved allocation)"), so overloading it would give one column two
 * meanings depending on the row type -- exactly the kind of thing that reads
 * as working code and is wrong.
 *
 * ROLLBACK: `php artisan migrate:rollback` drops all three columns and
 * everything stored in them. MySQL does not roll back DDL, so if this
 * migration half-applies, check which columns exist before re-running.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('relief_transactions', function (Blueprint $table) {
            $table->string('donor_type', 20)->nullable()->after('source_or_recipient');
            $table->string('donor_name')->nullable()->after('donor_type');
            $table->decimal('monetary_value', 12, 2)->nullable()->after('donor_name');
        });
    }

    public function down(): void
    {
        Schema::table('relief_transactions', function (Blueprint $table) {
            $table->dropColumn(['donor_type', 'donor_name', 'monetary_value']);
        });
    }
};
