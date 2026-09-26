<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Core identity tables.
 *
 * Staff and donors share one `users` table. One auth path, one 2FA
 * implementation, one password policy — `type` distinguishes them and roles
 * carry capability. See docs/PHASE-3-DATA-ARCHITECTURE.md §2.1.
 *
 * Indexed string columns are sized deliberately: in utf8mb4 a character can be
 * 4 bytes and InnoDB's index limit is 3072, so an indexed VARCHAR(255) eats
 * 1020 of it. 191 is the working ceiling for indexed text here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            // ── Identity ────────────────────────────────────────────────────
            $table->string('name', 191);
            $table->string('email', 191)->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();

            // Ghanaian numbers are normalised to E.164 (+233…) on write. The
            // raw input is kept alongside so support can recognise what the
            // donor actually typed. Blueprint risk DEL-9.
            $table->string('phone', 20)->nullable()->index();
            $table->string('phone_raw', 32)->nullable();
            $table->timestamp('phone_verified_at')->nullable();

            // ── Kind of account ─────────────────────────────────────────────
            // 'staff' or 'donor'. VARCHAR + PHP enum, never a MySQL ENUM:
            // altering an ENUM rewrites the table, and on a live users table
            // that is an outage. See §1.7.
            // No standalone index here: `type` is the leftmost column of both
            // composites below, so MySQL already uses those for a `type`-only
            // lookup. A redundant index costs write time and space on every
            // insert for no read benefit.
            $table->string('type', 32)->default('donor');

            // ── Profile ─────────────────────────────────────────────────────
            $table->string('job_title', 191)->nullable();
            $table->text('bio')->nullable();
            $table->string('locale', 10)->default('en');
            $table->string('timezone', 64)->default('Africa/Accra');

            // ── Two-factor ──────────────────────────────────────────────────
            // Mandatory for every staff role, optional for donors. Both secret
            // columns are encrypted at the model layer, so they are TEXT: the
            // ciphertext is far longer than the plaintext.
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();

            // ── Account state ───────────────────────────────────────────────
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('suspended_at')->nullable();
            $table->string('suspended_reason', 191)->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();

            // ── Communication preferences ───────────────────────────────────
            // Separate per channel. Marketing requires an explicit opt-in;
            // transactional mail (receipts) sends regardless and carries no
            // marketing content. Act 843 — Blueprint risk DEL-10.
            $table->boolean('accepts_email_marketing')->default(false);
            $table->boolean('accepts_sms_marketing')->default(false);
            $table->timestamp('marketing_consent_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Staff lists filter by type then sort by name; donors by type and
            // recency. Composite in that order because `type` is the filter.
            $table->index(['type', 'is_active']);
            $table->index(['type', 'created_at']);
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email', 191)->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
