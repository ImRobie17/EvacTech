<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DROP B1 -- flags a relief good whose quantity is a PESO AMOUNT, not a count
 * of physical things.
 *
 * WHY THIS COLUMN EXISTS. Financial Assistance goes in the stock ledger by the
 * client's decision, and its quantity is pesos: one unit, one peso. Without a
 * flag, a single 5,000-peso grant makes the shelter's "Received" counter read
 * 5,200 instead of 200, makes "Remaining" meaningless, and makes the projected
 * days-of-stock estimate -- which divides remaining stock by the daily
 * distribution rate -- pure noise.
 *
 * So every UNIT counter on both relief screens excludes monetary goods, and
 * their pesos are reported separately. The flag is on the GOOD rather than the
 * transaction because it is a property of the item, not of one receipt: every
 * Financial Assistance row is monetary, always.
 *
 * Defaults to false, so every existing good and every good a camp manager
 * creates from the Receive modal is treated as a physical count unless
 * somebody deliberately says otherwise.
 *
 * ROLLBACK: drops the column. The unit counters then include Financial
 * Assistance again and read high; nothing errors.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('relief_goods', function (Blueprint $table) {
            $table->boolean('is_monetary')->default(false)->after('category');
        });
    }

    public function down(): void
    {
        Schema::table('relief_goods', function (Blueprint $table) {
            $table->dropColumn('is_monetary');
        });
    }
};
