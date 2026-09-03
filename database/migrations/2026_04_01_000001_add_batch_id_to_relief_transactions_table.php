<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DROP C -- batch relief distribution.
 *
 * WHAT THIS COLUMN IS. One batch distribution writes one ReliefTransaction per
 * household per relief good -- twelve families times two items is twenty-four
 * rows, exactly the row shape a single distribution already writes. batch_id is
 * the only thing that says those rows were one act by one operator.
 *
 * WHY NULLABLE. Every row already in this table was written one household at a
 * time and has no batch. A null batch_id is not missing data; it IS the
 * statement "this was a single distribution", and the Distribution Log renders
 * those rows exactly as it did before this drop. Nothing backfills them.
 *
 * WHY A STRING AND NOT A BOOLEAN. A boolean would answer "was this a batch"
 * and nothing else. The id answers "which batch", which is what a future
 * collapsed log view needs, and it costs the same migration. The collapse
 * itself is deliberately NOT in this drop: it would have rewritten a working
 * paginated panel, and this drop is additive by design.
 *
 * WHY 36 AND INDEXED. A UUID string is 36 characters. The index is here for the
 * grouping query the collapse would run; it is cheap now and saves a second
 * migration later.
 *
 * ROLLBACK NOTE. MySQL does not roll back DDL. If this migration fails partway,
 * check whether the column landed before re-running:
 *
 *     SHOW COLUMNS FROM relief_transactions LIKE 'batch_id';
 *
 * If it is there, the table is already in the target state -- mark the
 * migration as run rather than executing it a second time. down() drops the
 * index first and then the column, in that order, because MySQL will not drop a
 * column an index still references.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('relief_transactions', function (Blueprint $table) {
            $table->string('batch_id', 36)->nullable()->index()->after('household_id');
        });
    }

    public function down(): void
    {
        Schema::table('relief_transactions', function (Blueprint $table) {
            $table->dropIndex(['batch_id']);
            $table->dropColumn('batch_id');
        });
    }
};
