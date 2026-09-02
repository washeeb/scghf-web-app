<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every authentication attempt, successful or not.
 *
 * Two jobs. Operationally it powers the new-device / new-IP alert and the
 * "active sessions" screen. For governance it is the record a trustee or
 * auditor asks for after an incident — which is why FAILED attempts are stored
 * too. A table holding only successes cannot show you a brute-force attempt.
 *
 * Deliberately NOT soft-deletable: an audit trail an admin can quietly remove
 * is not an audit trail. Pruned on a schedule by retention policy instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_histories', function (Blueprint $table) {
            $table->id();

            // Nullable: a failed attempt against an address with no account
            // still gets recorded, and that is exactly the interesting case.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // What was typed. Kept even when no user matched, so credential
            // stuffing against non-existent accounts is visible.
            $table->string('email_attempted', 191)->index();

            $table->string('outcome', 32)->index();   // success | failed | locked_out | two_factor_failed
            $table->string('ip_address', 45)->nullable()->index();
            $table->text('user_agent')->nullable();

            // Parsed from the UA for the "new device" comparison. Coarse on
            // purpose — enough to say "a new browser signed in", not enough to
            // fingerprint anyone.
            $table->string('device_type', 32)->nullable();   // desktop | mobile | tablet | bot
            $table->string('platform', 64)->nullable();
            $table->string('browser', 64)->nullable();

            // Country only, never a precise location. Enough to flag "a sign-in
            // from outside Ghana"; not enough to track a person's movements.
            $table->string('country_code', 2)->nullable();

            $table->boolean('was_two_factor_used')->default(false);
            $table->boolean('is_new_device')->default(false);

            $table->timestamp('created_at')->nullable()->index();

            // The two real access patterns: one user's recent history, and
            // recent failures from one IP.
            $table->index(['user_id', 'created_at']);
            $table->index(['ip_address', 'outcome', 'created_at'], 'login_histories_ip_outcome_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_histories');
    }
};
