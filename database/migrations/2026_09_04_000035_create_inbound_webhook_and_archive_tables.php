<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two gaps closed.
 *
 * ── 1. Nothing was ever going to call markBounced() ─────────────────────────
 *
 * Module 7 built a suppression list, gave `EmailLog` a `markBounced()` and a
 * `markComplained()`, and made the whole channel depend on bounces reaching it.
 * Then nothing was built to receive them. The list would have stayed empty, the
 * bounce rate would have climbed, and the first thing to stop being delivered
 * would have been donation receipts — which is precisely the failure the
 * suppression list exists to prevent.
 *
 * `inbound_webhook_events` is the receiving end, built on the same terms as
 * `payment_webhook_events` because the same things go wrong:
 *
 *   1. store the raw body BEFORE parsing, so a malformed payload is evidence
 *      rather than a 500 with nothing kept
 *   2. verify the signature and record the verdict, never discard on failure
 *   3. respond 200 immediately; process on the queue
 *   4. a unique event id, so a redelivery is a no-op rather than a second
 *      suppression
 *
 * Providers retry aggressively, and a webhook endpoint that is slow gets
 * hammered. This is the shape that survives it.
 *
 * ── 2. The audit archive (open question 16) ─────────────────────────────────
 *
 * `audit_logs` is never swept by the retention runner — it is the evidence that
 * the retention policy was followed, and a policy that destroys its own
 * evidence cannot be demonstrated. Kept for seven years.
 *
 * Which means that on a shared host it becomes the largest table in the
 * database, and every `SHOW TABLES` backup drags years of it across a slow
 * connection nightly. The answer is not to delete it; it is to move whole
 * closed years out of the live table into a compressed file, leaving behind a
 * row that proves what was moved and lets it be verified.
 *
 * The chain is what makes that safe. An archived year carries its first and
 * last hashes and a hash over the whole archive, so a restored file can be
 * checked against what the live table says was taken away — and the year
 * following it still chains onto the last archived hash, so removing a year
 * does not break the chain that remains.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbound_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            // mnotify | mailgun | postmark | resend | ses
            $table->string('provider', 32);

            // email | sms
            $table->string('channel', 16);

            /*
             * The provider's own id for this delivery, or a hash of the body
             * where it does not send one.
             *
             * Unique, and that uniqueness IS the idempotency guarantee — a
             * redelivery collides here instead of suppressing an address twice
             * or double-counting a bounce. Handled as a race rather than a
             * check-then-insert, because two simultaneous deliveries would both
             * pass a check.
             */
            $table->string('event_id', 191)->unique();

            // bounce | complaint | delivered | failed | opened — the provider's
            // own word for it, normalised on the way in.
            $table->string('event_type', 64)->nullable();

            // The address or number the event is about, normalised, so it can
            // be matched to a log row and to the suppression list.
            $table->string('subject_address', 191)->nullable();

            /*
             * The raw body, exactly as it arrived. Not the parsed version.
             *
             * A payload we could not parse is the one most worth keeping: it is
             * either a provider change or somebody probing the endpoint, and
             * both are things a person needs to look at.
             */
            $table->longText('raw_payload');

            $table->string('signature', 500)->nullable();

            /*
             * Recorded, never acted on when false.
             *
             * An unauthenticated event must not be able to suppress an address
             * — that would be a trivial denial of service against any donor
             * whose email somebody could guess. A run of these is the signal
             * that the endpoint is being probed.
             */
            $table->boolean('signature_valid')->default(false);

            $table->string('source_ip', 45)->nullable();

            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('error')->nullable();

            $table->timestamps();

            $table->index(['provider', 'event_type', 'received_at'], 'inbound_webhooks_triage_index');
            $table->index(['processed_at', 'received_at']);
            $table->index('subject_address');
        });

        Schema::create('audit_archives', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            // The closed period this archive covers. Whole years, because a
            // partial period is a period somebody has to reason about.
            $table->unsignedSmallInteger('year');

            $table->unsignedInteger('entry_count');

            // The id range removed, so the gap in the live table is explained
            // rather than merely present.
            $table->unsignedBigInteger('first_entry_id');
            $table->unsignedBigInteger('last_entry_id');
            $table->timestamp('period_start');
            $table->timestamp('period_end');

            /*
             * The chain, preserved across the gap.
             *
             * `first_entry_hash` is what the removed block started from;
             * `last_entry_hash` is what it ended on, and is what the first
             * surviving entry after the gap chains to. Without these, archiving
             * a year would look identical to somebody deleting one.
             */
            $table->char('first_entry_hash', 64);
            $table->char('last_entry_hash', 64);

            /*
             * SHA-256 of the archive file itself. What makes a restored file
             * checkable against what the live table says was taken away —
             * otherwise "we have the archive" is a claim about a file nobody
             * has verified.
             */
            $table->char('archive_hash', 64);

            $table->string('filename', 500);
            $table->string('disk', 32)->default('local');
            $table->unsignedBigInteger('size_bytes')->nullable();

            /*
             * Whether the entries have actually been removed from the live
             * table yet.
             *
             * Writing the archive and deleting the rows are separate steps on
             * purpose: the file is written and verified first, and only then is
             * anything removed. A crash between them leaves an unpruned archive,
             * which is harmless and re-runnable.
             */
            $table->timestamp('pruned_at')->nullable();

            $table->timestamp('verified_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('year');
            $table->index('pruned_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_archives');
        Schema::dropIfExists('inbound_webhook_events');
    }
};
