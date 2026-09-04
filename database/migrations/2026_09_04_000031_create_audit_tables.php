<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 8, part 1 — the audit trail and API tokens.
 *
 * ── Why this is not activity_log ────────────────────────────────────────────
 *
 * spatie/laravel-activitylog is already installed and already records model
 * CHANGES: this field was 5000, now it is 7000, and Ama did it.
 *
 * This table records ACTIONS, and the distinction is the whole reason it
 * exists. Somebody opening a beneficiary's file and reading their medical
 * history changes nothing. Somebody exporting four thousand donor records to a
 * spreadsheet changes nothing. Somebody running the retention sweep, or
 * releasing a suppression, or downloading a receipt, changes nothing that
 * activity_log would notice.
 *
 * Those are precisely the events Act 843 accountability turns on, and today
 * they leave no trace anywhere. An access log that only records writes answers
 * "who broke this?" but never "who read this?" — and for a foundation holding
 * files on vulnerable children, the second question is the more serious one.
 *
 * ── Append-only, and tamper-evident ─────────────────────────────────────────
 *
 * No `updated_at`, no `deleted_at`, and the model refuses both. Beyond that,
 * each row carries the SHA-256 of its own content chained to the previous row's
 * hash, so removing or editing an entry breaks every hash after it and
 * `scghf:verify-audit-log` says exactly where.
 *
 * Being honest about what that does and does not buy: it makes tampering
 * DETECTABLE, not impossible. Somebody with database access can rewrite the
 * whole chain from the tampered point onward. What defeats that is anchoring —
 * periodically writing the head hash somewhere outside the database, which the
 * verify command does to the application log. An auditor comparing the two is
 * the actual control; the chain is what makes the comparison meaningful.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            // Auto-increment, and here the SEQUENCE is load-bearing: the hash
            // chain is ordered by it. This is not a table where the id is
            // merely a join key.
            $table->id();
            $table->ulid('ulid')->unique();

            // donor.pii_viewed · donations.exported · retention.executed ·
            // suppression.released · admin.impersonation_started
            $table->string('event', 64);

            // auth | data_access | data_export | money | privacy | config |
            // security | safeguarding
            $table->string('category', 32)->default('security');

            // info | notice | warning | critical
            $table->string('severity', 16)->default('info');

            /*
             * A sentence, written for the person reading it two years later —
             * usually an auditor or a trustee, not an engineer. "Exported 4,182
             * donor records including email and phone" is evidence; "export"
             * is a word.
             */
            $table->string('description', 500);

            $table->nullableMorphs('subject');

            $table->foreignId('causer_id')->nullable()->constrained('users')->nullOnDelete();

            /*
             * Who they were, snapshotted.
             *
             * The foreign key nulls when a staff member is deleted, and an
             * audit trail that forgets who did something the moment they leave
             * is not an audit trail. This column survives them.
             */
            $table->string('causer_label', 191)->nullable();

            /*
             * Impersonation, recorded separately from the causer.
             *
             * An administrator acting as another user must never appear in the
             * log as that user. "Ama did this" and "Kofi did this while
             * impersonating Ama" are different facts, and only one of them is
             * fair to Ama.
             */
            $table->foreignId('impersonator_id')->nullable()->constrained('users')->nullOnDelete();

            // Scrubbed before it gets here — never a request body, never a card
            // number, never a password field.
            $table->json('context')->nullable();

            /*
             * How many records the action touched.
             *
             * The number that turns a routine event into an incident. One donor
             * record viewed is a member of staff doing their job; four thousand
             * exported at two in the morning is not.
             */
            $table->unsignedInteger('record_count')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            $table->timestamp('occurred_at');

            /*
             * The chain. `previous_hash` is null only on the very first row;
             * `hash` is unique, so an identical row cannot be inserted twice
             * and a duplicated chain link fails loudly.
             */
            $table->char('previous_hash', 64)->nullable();
            $table->char('hash', 64)->unique();

            // created_at only. There is no update path, so there is nothing an
            // updated_at could honestly record.
            $table->timestamp('created_at')->nullable();

            $table->index(['event', 'occurred_at']);
            $table->index(['causer_id', 'occurred_at']);
            $table->index(['category', 'severity', 'occurred_at'], 'audit_logs_triage_index');
            $table->index('occurred_at');
        });

        /*
        |----------------------------------------------------------------------
        | API tokens
        |----------------------------------------------------------------------
        |
        | For a future mobile app or an integration. Small table, strict rules.
        */
        Schema::create('api_tokens', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->string('name', 100);

            /*
             * The SHA-256 of the token, never the token.
             *
             * A leaked database must not be a leaked set of live credentials.
             * The plaintext is shown once, at creation, and is not recoverable
             * afterwards — which is inconvenient exactly once and safe for ever.
             *
             * SHA-256 rather than bcrypt deliberately: this value is looked up
             * on every API request, so it has to be indexable and constant-time
             * cheap. That is safe here because the token is 40 random bytes,
             * not a human-chosen password — there is no dictionary to attack.
             */
            $table->char('token_hash', 64)->unique();

            /*
             * The first few characters, stored in clear.
             *
             * Lets somebody identify a token in a list, and match one found in
             * a log or a config file to a row here, without the row revealing
             * anything usable.
             */
            $table->string('prefix', 12)->index();

            // A token acts AS somebody. An action with no actor cannot be
            // audited, and every action here is audited.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            /*
             * Explicit abilities, defaulting to NONE.
             *
             * Deny by default: a token created without thinking about what it
             * may do can do nothing. The opposite default — a token that can do
             * everything unless restricted — is how an integration built to read
             * event listings ends up able to issue refunds.
             */
            $table->json('abilities')->nullable();

            // Optional allowlist. A server integration has a fixed address; a
            // token that only works from it is a token a leak cannot use.
            $table->json('allowed_ips')->nullable();

            /*
             * NOT NULL, deliberately.
             *
             * A token with no expiry is a permanent credential that nobody
             * remembers issuing, sitting in a config file on a laptop that left
             * the organisation three years ago. Everything here expires; the
             * ceiling is in config/system.php.
             */
            $table->timestamp('expires_at');

            $table->timestamp('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();
            $table->unsignedInteger('use_count')->default(0);

            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('revoke_reason', 191)->nullable();

            // Per-token, so one noisy integration cannot exhaust a shared
            // account's PHP processes.
            $table->unsignedSmallInteger('rate_limit_per_minute')->default(60);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'revoked_at']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_tokens');
        Schema::dropIfExists('audit_logs');
    }
};
