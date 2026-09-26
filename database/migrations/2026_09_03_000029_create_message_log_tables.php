<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 7, part 2 — delivery logs, the in-app inbox and the outbox.
 *
 * ── A refusal is an outcome, not an absence ─────────────────────────────────
 *
 * Every attempt is logged, INCLUDING the ones that never left: suppressed,
 * disabled, expired, failed. A receipt that was not delivered because the
 * donor's address is suppressed is a fact Finance needs — they can post it or
 * hand it over — and a row saying `suppressed` is how they learn. Nothing here
 * is allowed to fail by leaving no trace.
 *
 * ── The throttle is a COUNT, not a counter ──────────────────────────────────
 *
 * InMotion shared hosting caps outbound mail per hour, and the queue runs from
 * cron in fifty-five-second bursts, so each invocation is a fresh PHP process
 * with no memory of the last. A counter kept anywhere else would drift the
 * first time a worker was killed mid-batch.
 *
 * So the rate limiter counts rows in `email_logs` with `sent_at` inside the
 * window. It is exactly what was actually sent, it is atomic without a lock,
 * and it self-corrects. That is what `(sent_at)` is indexed for.
 *
 * ── Bodies ──────────────────────────────────────────────────────────────────
 *
 * Stored for transactional mail, where proving what was sent matters. NOT
 * stored for campaign mail: two thousand copies of the same 40KB newsletter is
 * 80MB of a shared disk quota to record nothing that the campaign row does not
 * already hold.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
        |----------------------------------------------------------------------
        | Email log
        |----------------------------------------------------------------------
        */
        Schema::create('email_logs', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->foreignId('email_template_id')->nullable()
                ->constrained()->nullOnDelete();

            // Snapshot of the key. Survives the template being renamed or
            // removed, which the foreign key deliberately does not.
            $table->string('template_key', 64)->nullable();

            $table->string('category', 32)->default('transactional');

            $table->string('to_address', 191);
            $table->string('to_name', 191)->nullable();
            $table->string('from_address', 191)->nullable();
            $table->string('reply_to', 191)->nullable();

            $table->string('subject', 255);

            /*
             * What was actually sent. Null for bulk — see the note above the
             * class. `body_stored` says which case this row is, so an empty
             * body is never ambiguous between "not kept" and "was empty".
             */
            $table->longText('body_html')->nullable();
            $table->longText('body_text')->nullable();
            $table->boolean('body_stored')->default(false);

            // What this message was about: a donation, an order, a volunteer
            // application. Lets a donation screen show its own correspondence.
            $table->nullableMorphs('related');

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            /*
             * queued | sending | sent | delivered | soft_bounced | bounced |
             * complained | failed | suppressed | disabled | cancelled | expired
             *
             * `sent` and `delivered` are separate on purpose. We know we handed
             * the message to a transport; we only know it arrived if something
             * tells us so.
             */
            $table->string('status', 32)->default('queued');

            // Why it did not go, in words a person can act on.
            $table->string('blocked_reason', 191)->nullable();
            $table->text('error')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);

            // Which transport handled it, and the id it gave back — the only
            // way to trace a message in a provider's own dashboard.
            $table->string('mailer', 32)->nullable();
            $table->string('provider_message_id', 191)->nullable();

            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();

            /*
             * Open and click tracking is OFF (config/communications.php).
             * Recording that a named person read a message, when, is Act 843
             * processing that the Foundation has not decided to justify. The
             * columns exist so enabling it later is a config change rather than
             * a migration; the dispatcher never writes them while it is off.
             */
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('clicked_at')->nullable();

            $table->timestamps();

            // Drives the hourly throttle. Must stay.
            $table->index('sent_at');
            $table->index(['status', 'created_at']);
            $table->index(['to_address', 'created_at']);
            $table->index(['template_key', 'created_at']);
            $table->index('provider_message_id');
        });

        /*
        |----------------------------------------------------------------------
        | SMS log
        |----------------------------------------------------------------------
        |
        | With SMS_DRIVER=log this table fills up exactly as if messages were
        | being sent, costed and segmented, without a provider account existing.
        | That is the point: the Foundation can see what a month of SMS would
        | have cost before committing to a contract.
        */
        Schema::create('sms_logs', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->foreignId('sms_template_id')->nullable()->constrained()->nullOnDelete();
            $table->string('template_key', 64)->nullable();
            $table->string('category', 32)->default('transactional');

            // E.164. Normalised before it gets here, or it is not a phone
            // number, it is a string that looks like one.
            $table->string('to_number', 20);

            // mtn | telecel | at | glo | unknown — from the prefix, for cost
            // attribution against the provider's invoice. Never for routing:
            // number portability means the prefix is the original network.
            $table->string('network', 16)->default('unknown');

            $table->string('sender_id', 11);
            $table->text('body');

            // The measurement that determines the bill.
            $table->string('encoding', 8)->default('gsm7');
            $table->unsignedSmallInteger('character_count')->default(0);
            $table->unsignedTinyInteger('segments')->default(1);

            // Integer pesewas, like every other amount in this application.
            // Named `estimated` because it is our rate, not the invoice.
            $table->unsignedBigInteger('estimated_cost_minor')->default(0);
            $table->char('currency', 3)->default('GHS');

            $table->string('driver', 32)->default('log');
            $table->string('provider_message_id', 191)->nullable();

            /*
             * What the provider said, verbatim, kept apart from our own status.
             *
             * An unregistered sender ID is accepted by the provider and dropped
             * by the network with no error anywhere. So a message we handed
             * over successfully and heard nothing more about is `unknown`, never
             * `delivered` — assuming delivery is how a silently blocked sender
             * ID goes unnoticed for a month.
             */
            $table->string('provider_status', 32)->nullable();

            $table->string('status', 32)->default('queued');
            $table->string('blocked_reason', 191)->nullable();
            $table->text('error')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);

            $table->nullableMorphs('related');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();

            $table->timestamps();

            $table->index('sent_at');
            $table->index(['status', 'created_at']);
            $table->index(['to_number', 'created_at']);
            $table->index(['network', 'sent_at']);
        });

        /*
        |----------------------------------------------------------------------
        | Notification log — the in-app inbox
        |----------------------------------------------------------------------
        |
        | Separate from Laravel's own `notifications` table because this records
        | the DISPATCH: which channels were asked for and which actually
        | delivered. "We tried to text them and the SMS was suppressed, so the
        | email is the only copy" is a question somebody will ask.
        */
        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            // Who it is for. A user, or a donor with no account.
            $table->morphs('notifiable');

            $table->string('key', 64);
            $table->string('title', 191);
            $table->text('body')->nullable();

            $table->string('action_url', 500)->nullable();
            $table->string('action_label', 64)->nullable();

            // info | success | warning | urgent
            $table->string('level', 16)->default('info');

            // Requested vs achieved. The gap between them is the interesting part.
            $table->json('channels')->nullable();
            $table->json('delivered_channels')->nullable();

            $table->nullableMorphs('related');

            $table->timestamp('read_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();

            $table->timestamps();

            $table->index(['notifiable_type', 'notifiable_id', 'read_at'], 'notification_logs_inbox_index');
            $table->index(['key', 'created_at']);
        });

        /*
        |----------------------------------------------------------------------
        | Scheduled messages — the outbox
        |----------------------------------------------------------------------
        |
        | Not Laravel's queue. The queue is for work; this is for messages, and
        | the difference matters on shared hosting: a message needs to be
        | visible, cancellable, re-orderable by priority, and above all
        | EXPIRABLE, none of which a serialised job in `jobs` is.
        */
        Schema::create('scheduled_messages', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            // email | sms
            $table->string('channel', 16);
            $table->string('template_key', 64)->nullable();
            $table->string('category', 32)->default('transactional');

            $table->string('to_address', 191);
            $table->string('to_name', 191)->nullable();

            // The variables the template will be rendered against, resolved at
            // SEND time rather than now. A cause name edited between scheduling
            // and sending should reach the reader corrected — with the
            // deliberate exception of receipts, which snapshot their wording at
            // issue because a receipt is a record of what was said then.
            $table->json('payload')->nullable();

            $table->nullableMorphs('related');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamp('send_after');

            /*
             * After this, the message is not sent — it is expired and logged as
             * expired.
             *
             * The failure it prevents is specific to this host: two hundred
             * messages an hour means a backlog is measured in days, and a
             * backlog that eventually delivers "Reminder: the event is
             * tomorrow" three days after the event is worse than one that
             * delivers nothing and says so.
             *
             * Null for receipts. A receipt is worth delivering late.
             */
            $table->timestamp('expires_at')->nullable();

            // Lower sends first. Transactional beats bulk, so a receipt does
            // not wait behind four hundred newsletter sends.
            $table->unsignedTinyInteger('priority')->default(5);

            // pending | claimed | sent | failed | cancelled | expired | suppressed
            $table->string('status', 32)->default('pending');

            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('last_error')->nullable();

            /*
             * Claiming. Cron starts a worker every minute and the previous one
             * may still be finishing, so two processes can see the same row.
             * A claim under lockForUpdate, released after a TTL if the worker
             * died, is what stops a donor getting two receipts.
             */
            $table->timestamp('claimed_at')->nullable();
            $table->string('claimed_by', 64)->nullable();

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 191)->nullable();

            /*
             * Belt and braces against the same message being scheduled twice —
             * a webhook replayed, a retry after a timeout that actually
             * succeeded. Unique, so the second attempt is a database error
             * rather than a second receipt.
             */
            $table->string('idempotency_key', 191)->nullable()->unique();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // The drain query, in the order it filters.
            $table->index(['status', 'send_after', 'priority'], 'scheduled_messages_drain_index');
            $table->index(['channel', 'status']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_messages');
        Schema::dropIfExists('notification_logs');
        Schema::dropIfExists('sms_logs');
        Schema::dropIfExists('email_logs');
    }
};
