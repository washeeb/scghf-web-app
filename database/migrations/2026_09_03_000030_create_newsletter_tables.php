<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 7, part 3 — newsletters and campaigns.
 *
 * Deferred out of Module 6 on purpose: a campaign needs templates to render
 * from and a suppression list to check against, and building it first would
 * have meant hardcoding wording against CLAUDE.md's CMS rule and sending
 * without a suppression check.
 *
 * ── Consent is re-checked per message, not per campaign ─────────────────────
 *
 * This is the design consequence of the hosting constraint, and it is the most
 * important thing on this page.
 *
 * The host sends a couple of hundred messages an hour, so a campaign to two
 * thousand subscribers takes most of a day. Between building the recipient list
 * and sending the last message, HOURS pass — and somebody who unsubscribes in
 * hour three has unsubscribed. Checking consent once when the list is built
 * would keep mailing them for the rest of the day, from a list that was
 * accurate when it was made and is not any more.
 *
 * So `campaign_recipients` is a build list and a claim queue, not a permission.
 * The permission is re-read at the moment each message goes.
 *
 * ── A campaign cannot be sent by accident ───────────────────────────────────
 *
 * Two gates, both defaults in config/communications.php:
 *
 *   - a test send must have happened, so a broken merge tag or a dead link is
 *     found by one person rather than by two thousand
 *   - an approval must be recorded, by somebody holding `newsletter.send`,
 *     which the permission set already separates from `newsletter.draft`
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
        |----------------------------------------------------------------------
        | Newsletters — the lists somebody can be on
        |----------------------------------------------------------------------
        |
        | A list, not a mailing. `subscribers.topics` already lets somebody take
        | the impact update without the fundraising appeals; this is the table
        | those topics refer to, so the preference means something concrete.
        */
        Schema::create('newsletters', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->string('key', 64)->unique();
            $table->string('name', 191);
            $table->text('description')->nullable();

            /*
             * The value that appears in `subscribers.topics`. Kept separate
             * from `key` so a list can be renamed without orphaning every
             * subscriber's stored preference.
             */
            $table->string('topic', 64)->index();

            $table->foreignId('division_id')->nullable()->constrained()->nullOnDelete();

            $table->string('from_name', 191)->nullable();
            $table->string('from_address', 191)->nullable();
            $table->string('reply_to', 191)->nullable();

            // The shell a campaign is rendered into, so branding is not
            // rebuilt per campaign.
            $table->foreignId('email_template_id')->nullable()
                ->constrained()->nullOnDelete();

            /*
             * How often this list expects to hear from the Foundation, shown on
             * the signup form. Somebody who was promised "monthly" and receives
             * weekly appeals unsubscribes — and the unsubscribe is the polite
             * version of what else they could do.
             */
            $table->string('cadence', 32)->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        /*
        |----------------------------------------------------------------------
        | Campaigns — one mailing
        |----------------------------------------------------------------------
        */
        Schema::create('newsletter_campaigns', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->foreignId('newsletter_id')->constrained()->cascadeOnDelete();

            $table->string('title', 191);
            $table->string('subject', 255);
            $table->string('preheader', 191)->nullable();

            /*
             * The body lives HERE, once — not copied onto two thousand log
             * rows. Forty kilobytes times two thousand recipients is eighty
             * megabytes of a shared disk quota to store nothing new.
             */
            $table->longText('body_html');
            $table->longText('body_text')->nullable();

            $table->foreignId('email_template_id')->nullable()
                ->constrained()->nullOnDelete();

            // draft | scheduled | building | sending | paused | sent |
            // cancelled | failed
            $table->string('status', 32)->default('draft');

            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->string('pause_reason', 191)->nullable();

            // Counters, maintained as the send progresses. On a send measured
            // in hours, "how far through is it" is the question staff ask.
            $table->unsignedInteger('recipient_count')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);

            /*
             * The test send. Required before a real one: a broken merge tag or
             * a dead link should be found by one person, not by two thousand,
             * and a campaign cannot be recalled.
             */
            $table->timestamp('test_sent_at')->nullable();
            $table->string('test_sent_to', 191)->nullable();

            /*
             * Approval. `newsletter.draft` and `newsletter.send` are already
             * separate permissions; this is what makes the separation mean
             * something at the model layer rather than only in the UI.
             */
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            // Optional narrowing: a division's supporters, particular topics.
            $table->foreignId('division_id')->nullable()->constrained()->nullOnDelete();
            $table->json('topics')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'scheduled_for']);
            $table->index(['newsletter_id', 'status']);
        });

        /*
        |----------------------------------------------------------------------
        | Campaign recipients — the build list, not a permission
        |----------------------------------------------------------------------
        |
        | Who this campaign is FOR. Whether it may actually go to them is read
        | from the suppression list at the moment of sending, hours later.
        |
        | Deliberately has no `ulid`: it is never exposed, never in a URL, and
        | two thousand rows per campaign is not a place to spend twenty-six
        | bytes and a unique index for nothing.
        */
        Schema::create('campaign_recipients', function (Blueprint $table) {
            $table->id();

            $table->foreignId('newsletter_campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscriber_id')->nullable()->constrained()->nullOnDelete();

            // Snapshot. Survives the subscriber row being deleted, which is
            // what makes "who did we actually email" answerable afterwards.
            $table->string('email', 191);
            $table->string('name', 191)->nullable();

            // pending | sent | skipped | failed
            $table->string('status', 32)->default('pending');

            // suppressed | unconfirmed | unsubscribed | invalid — why this
            // person did not receive it, in a word staff can filter on.
            $table->string('skip_reason', 64)->nullable();

            $table->foreignId('email_log_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('sent_at')->nullable();

            $table->timestamps();

            // Dedup at build time. Somebody subscribed twice under two names
            // gets one copy.
            $table->unique(['newsletter_campaign_id', 'email'], 'campaign_recipients_unique');
            $table->index(['newsletter_campaign_id', 'status'], 'campaign_recipients_progress');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_recipients');
        Schema::dropIfExists('newsletter_campaigns');
        Schema::dropIfExists('newsletters');
    }
};
