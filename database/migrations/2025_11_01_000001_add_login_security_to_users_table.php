<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 7 ITEM 4 -- login attempt limit.
 *
 * Two columns rather than a cache entry on purpose. A cache-backed counter
 * disappears on `php artisan cache:clear` and on a restart of the file cache,
 * which would silently unlock every account -- and an administrator cannot see
 * a cache key, so the Unlock button in User Management would have nothing to
 * act on. State that an admin has to be able to inspect and clear belongs in
 * the table.
 *
 * ROLLBACK: `php artisan migrate:rollback --step=1` drops both columns. Nothing
 * else reads them, and LoginController degrades to its pre-Phase-7 behaviour
 * only if its own file is also reverted -- so roll the code back too, or the
 * login screen will error on a missing column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Tiny int: the cap is 3. Unsigned so it can never go negative
            // through an arithmetic bug and wrap into a huge allowance.
            $table->unsignedTinyInteger('failed_login_attempts')->default(0)->after('status');

            // NULL means "not locked". Deliberately a point in time rather than
            // a boolean: the lock expires on its own, so nothing has to run on a
            // schedule to release it, and a lock cannot survive a forgotten
            // cron job. Checked on read, the same way transfer overdue is.
            $table->timestamp('locked_until')->nullable()->after('failed_login_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['failed_login_attempts', 'locked_until']);
        });
    }
};
