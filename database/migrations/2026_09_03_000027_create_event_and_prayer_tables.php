<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 6, part 2 — events and prayer requests.
 *
 * ── Photography at events ───────────────────────────────────────────────────
 *
 * The foundation photographs its events, and those photographs end up on the
 * website. An attendee has to be asked, and has to be able to say no without
 * being turned away — so `photography_consent` is a per-registration answer
 * with no default, and the consents table records it properly where somebody
 * wants it revocable later.
 *
 * A registration is NOT a mailing list. Consent to be contacted about this
 * event is not consent to a newsletter, and the two are separate columns for
 * exactly that reason.
 *
 * ── Prayer requests are confidential by default ─────────────────────────────
 *
 * A prayer request routinely carries the most sensitive thing anybody
 * volunteers to this foundation — an illness, a bereavement, a marriage in
 * trouble — offered in confidence to people who will pray about it.
 *
 * So `is_confidential` defaults to TRUE and publication requires an explicit,
 * separate consent. Publishing "pray for Ama, who has been diagnosed with
 * cancer" because a website needs content would be a serious Act 843 breach and
 * an unforgivable betrayal of the person who asked.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
        |----------------------------------------------------------------------
        | Events
        |----------------------------------------------------------------------
        */
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->foreignId('division_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cause_id')->nullable()->constrained()->nullOnDelete();

            $table->string('title', 191);
            $table->string('slug', 191)->unique();
            $table->text('summary')->nullable();
            $table->longText('description')->nullable();

            // outreach | fundraiser | service | training | community | other
            $table->string('event_type', 32)->default('outreach');

            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();

            $table->string('venue_name', 191)->nullable();
            $table->string('address')->nullable();
            $table->string('area', 191)->nullable();
            $table->string('region', 191)->nullable();

            $table->boolean('is_online')->default(false);
            $table->string('online_url', 500)->nullable();

            /*
             * Physical accessibility, described rather than reduced to a
             * checkbox. "Step-free entrance, no accessible WC" is useful to
             * somebody deciding whether to come; a tick is not.
             */
            $table->text('accessibility_notes')->nullable();

            $table->boolean('registration_required')->default(false);
            $table->unsignedInteger('capacity')->nullable();
            $table->unsignedInteger('registered_count')->default(0);
            $table->timestamp('registration_opens_at')->nullable();
            $table->timestamp('registration_closes_at')->nullable();

            /*
             * Ticketing is behind a feature flag and OFF. The column exists so
             * turning it on later is a migration nobody has to write, but the
             * shop's payment path is what would price it — not a second one.
             */
            $table->boolean('is_ticketed')->default(false);
            $table->unsignedBigInteger('ticket_price_minor')->nullable();
            $table->char('currency', 3)->default('GHS');

            // scheduled | cancelled | postponed | completed
            $table->string('status', 32)->default('scheduled');
            $table->string('cancellation_reason', 191)->nullable();

            $table->foreignId('featured_image_id')->nullable()->constrained('media')->nullOnDelete();

            $table->boolean('is_featured')->default(false);
            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_published', 'starts_at']);
            $table->index(['division_id', 'starts_at']);
            $table->index(['status', 'starts_at']);
        });

        /*
        |----------------------------------------------------------------------
        | Registrations
        |----------------------------------------------------------------------
        */
        Schema::create('event_registrations', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->string('reference', 32)->unique();

            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name', 191);
            $table->string('email', 191)->nullable();
            $table->string('phone', 32)->nullable();

            // People brought along. Counted against capacity, because a room
            // holds people rather than bookings.
            $table->unsignedSmallInteger('guests')->default(0);

            // registered | waitlisted | attended | no_show | cancelled
            $table->string('status', 32)->default('registered');

            /*
             * NULLABLE, with no default. Photography consent has to be ASKED,
             * and "we never asked" must be distinguishable from "they said no".
             * A boolean defaulting to false would silently record a refusal
             * nobody obtained.
             */
            $table->boolean('photography_consent')->nullable();

            /*
             * Separate from photography, and separate again from the newsletter.
             * Consent to be contacted about THIS event is not consent to a
             * mailing list, and one column cannot express both.
             */
            $table->boolean('contact_consent')->default(false);
            $table->boolean('newsletter_consent')->default(false);
            $table->text('consent_text')->nullable();
            $table->string('consent_ip', 45)->nullable();
            $table->timestamp('consent_at')->nullable();

            $table->text('accessibility_needs')->nullable();
            $table->text('dietary_needs')->nullable();
            $table->text('notes')->nullable();

            $table->timestamp('checked_in_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();

            // One registration per email per event. A second attempt updates
            // rather than creating a duplicate nobody expects on a door list.
            $table->unique(['event_id', 'email']);
            $table->index(['event_id', 'status']);
            $table->index('checked_in_at');
        });

        /*
        |----------------------------------------------------------------------
        | Prayer requests
        |----------------------------------------------------------------------
        */
        Schema::create('prayer_requests', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->string('reference', 32)->unique();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('division_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name', 191)->nullable();
            $table->string('email', 191)->nullable();
            $table->string('phone', 32)->nullable();

            // Submitted without a name at all. Different from confidential:
            // this one the foundation itself cannot identify.
            $table->boolean('is_anonymous')->default(false);

            // health | family | bereavement | finance | work | spiritual
            // | thanksgiving | other
            $table->string('category', 32)->default('other');

            $table->longText('request');

            /*
             * DEFAULTS TO TRUE, and that default is the design.
             *
             * Confidential means the request is seen by the prayer team and
             * nobody else. Publishing it — even anonymised — needs the separate
             * explicit consent below.
             */
            $table->boolean('is_confidential')->default(true);

            $table->boolean('consent_to_publish')->default(false);
            $table->boolean('consent_to_share_with_team')->default(true);
            $table->boolean('publish_anonymously')->default(true);
            $table->text('consent_text')->nullable();
            $table->string('consent_ip', 45)->nullable();
            $table->timestamp('consent_at')->nullable();

            // new | praying | answered | closed
            $table->string('status', 32)->default('new');

            $table->unsignedInteger('prayed_count')->default(0);

            $table->text('answered_note')->nullable();
            $table->timestamp('answered_at')->nullable();

            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();

            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();

            $table->string('submitted_ip', 45)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'created_at']);
            $table->index(['is_published', 'published_at']);
            $table->index(['category', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prayer_requests');
        Schema::dropIfExists('event_registrations');
        Schema::dropIfExists('events');
    }
};
