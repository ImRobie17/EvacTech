<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 7 ITEM 5 -- password reset requests.
 *
 * NOT Laravel's built-in password_reset_tokens table and deliberately nothing
 * like it. There is no mail server in this deployment and no self-service reset:
 * the flow is that a locked-out staff member raises a request, an administrator
 * telephones them to confirm it is really them, and only then sets a new
 * password using the account editor that already exists. Contact happens OFF
 * system. This table is the queue, not a channel.
 *
 * That is also why there is no token column. A token would imply a link someone
 * can click to reset their own password, which is exactly the thing this design
 * declines to do.
 *
 * ROLLBACK: `php artisan migrate:rollback --step=1` drops the table. It holds
 * only queue state -- no evacuee data, no credentials -- so dropping it loses
 * nothing an administrator cannot rebuild by asking staff to request again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_reset_requests', function (Blueprint $table) {
            $table->id();

            // cascadeOnDelete: a request for an account that no longer exists is
            // noise in an administrator's queue, not history worth keeping.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Optional, and separate from users.contact_number on purpose: the
            // number on file may be the one they have lost access to, so the
            // form lets them leave a number they can actually be reached on.
            $table->string('contact_number', 20)->nullable();

            $table->string('status', 20)->default('pending'); // pending, completed, dismissed

            // Who acted, and when. Null while pending.
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();

            // The request form is unauthenticated, so this is the only trace of
            // where a request came from if the queue is ever spammed.
            $table->string('requested_ip', 45)->nullable();

            $table->timestamps();

            // Every screen that reads this table filters on status first.
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_requests');
    }
};
