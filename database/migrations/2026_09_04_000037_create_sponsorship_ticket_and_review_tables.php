<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The last three tables named in the design and never built.
 *
 * ── Sponsorship: a flag that was ON with nothing behind it ──────────────────
 *
 * `features.sponsorship` has been true since Phase 2. Nothing implemented it.
 * That is the worst of the three possible states — a flag that is off is a
 * decision, a flag that is on and built is a feature, and a flag that is on
 * and empty is a promise the application cannot keep.
 *
 * It is also the single most safeguarding-sensitive thing this foundation
 * could build. Sponsorship links a named adult donor to a named vulnerable
 * child and then sends that adult photographs and news about them, month after
 * month. Done carelessly it is a system for introducing strangers to children
 * and telling them where to find them.
 *
 * So the schema below is shaped by three refusals:
 *
 *   1. The sponsor is told about a child, never HOW TO REACH one. There is no
 *      column for a sponsor's message to a child, because there is no such
 *      route — correspondence goes through staff or it does not happen.
 *
 *   2. Nothing about a child leaves the building without a live consent. Every
 *      update carries the consent it was sent under, so "we had permission" is
 *      a row rather than a recollection.
 *
 *   3. What the sponsor learns is generalised by default: a first name, an age
 *      band, a district, a programme. Not a full name, not a date of birth, not
 *      a community, and never coordinates — the same boundary the analytics
 *      dataset uses, for the same reason.
 *
 * ── Tickets and reviews ─────────────────────────────────────────────────────
 *
 * `event_tickets` was named in §2.6 and referenced by `events.is_ticketed`,
 * which the model already refuses to set without it. `product_reviews` was
 * named in §2.5 and, more pointedly, `reviews.moderate` has been a granted
 * permission since Module 1 — a permission over a table that did not exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
        |----------------------------------------------------------------------
        | Sponsorships
        |----------------------------------------------------------------------
        */
        Schema::create('sponsorships', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('reference', 32)->unique();

            $table->foreignId('donor_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            /*
             * The child or student.
             *
             * `nullOnDelete`, so a sponsorship survives the beneficiary record
             * being destroyed at retention expiry. The financial trail outlives
             * the person — which is the whole point of de-identifying rather
             * than deleting — and a sponsor's payment history must not vanish
             * because a case closed.
             */
            $table->foreignId('beneficiary_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('division_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();

            // The recurring gift that pays for it. Sponsorship does not invent
            // a second payment path; it rides the one that already exists.
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3)->default('GHS');

            // monthly | quarterly | annually
            $table->string('frequency', 32)->default('monthly');

            // pending | active | paused | ended
            $table->string('status', 32)->default('pending');

            $table->date('started_on')->nullable();
            $table->date('ended_on')->nullable();

            // sponsor_ended | child_left_programme | child_completed |
            // safeguarding | non_payment | other
            $table->string('end_reason', 32)->nullable();

            /*
             * What this sponsor may be told, and it is deliberately narrow.
             *
             * `may_receive_photographs` is separate from `may_receive_updates`
             * because they are different permissions from the child's side: a
             * written note about school progress and a photograph of a child
             * sent to a stranger's inbox are not the same disclosure.
             *
             * Both default to FALSE. A sponsorship that has not been through
             * the consent check tells the sponsor nothing at all.
             */
            $table->boolean('may_receive_updates')->default(false);
            $table->boolean('may_receive_photographs')->default(false);

            /*
             * Whether the sponsor may know the child's given name.
             *
             * Off by default. "Ama, 9, in the BrightPath education programme in
             * Tamale district" is enough for a sponsor to feel connected and is
             * not enough to find her.
             */
            $table->boolean('may_know_given_name')->default(false);

            /*
             * There is deliberately NO column for direct contact between
             * sponsor and child — no address, no phone, no message thread.
             * Correspondence goes through staff or it does not happen, and the
             * absence of the column is what makes that true rather than
             * policy.
             */
            $table->foreignId('matched_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('matched_at')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'started_on']);
            $table->index('beneficiary_id');
            $table->index('donor_id');
        });

        /*
        |----------------------------------------------------------------------
        | Sponsorship updates — what actually goes to the sponsor
        |----------------------------------------------------------------------
        */
        Schema::create('sponsorship_updates', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->foreignId('sponsorship_id')->constrained()->cascadeOnDelete();

            $table->string('title', 191);
            $table->text('body');

            $table->foreignId('photograph_id')->nullable()->constrained('media')->nullOnDelete();

            /*
             * The consent this update was sent under, recorded on the update
             * itself.
             *
             * Not looked up at send time and forgotten — stored, so that in two
             * years "we had permission to send that photograph" is a row
             * pointing at a signed form, rather than somebody's recollection.
             */
            $table->foreignId('consent_id')->nullable()->constrained()->nullOnDelete();

            /*
             * Nothing reaches a sponsor unread by a member of staff.
             *
             * An update about a child, written by a field worker in a hurry,
             * can contain a school name, a village, a surname or a photograph
             * taken in front of a house. The review is where those come out.
             */
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->timestamp('sent_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['sponsorship_id', 'sent_at']);
        });

        /*
        |----------------------------------------------------------------------
        | Event tickets
        |----------------------------------------------------------------------
        |
        | Named in §2.6 and referenced by `events.is_ticketed`, which the Event
        | model already refuses to set while the feature flag is down.
        */
        Schema::create('event_tickets', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->foreignId('event_id')->constrained()->cascadeOnDelete();

            $table->string('name', 100);
            $table->text('description')->nullable();

            // Integer pesewas. Zero is a real answer — a free ticket that still
            // needs a place reserved is the common case at an outreach event.
            $table->unsignedBigInteger('price_minor')->default(0);
            $table->char('currency', 3)->default('GHS');

            $table->unsignedInteger('quantity')->nullable();
            $table->unsignedInteger('sold')->default(0);

            /*
             * How many one person may take. Without it, one supporter books
             * forty places for a hall that seats a hundred and the event looks
             * full while the room is empty.
             */
            $table->unsignedSmallInteger('max_per_order')->default(10);

            $table->timestamp('sales_open_at')->nullable();
            $table->timestamp('sales_close_at')->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['event_id', 'is_active']);
        });

        /*
        |----------------------------------------------------------------------
        | Product reviews
        |----------------------------------------------------------------------
        |
        | `reviews.moderate` has been a granted permission since Module 1, over
        | a table that did not exist. A permission that grants nothing is a
        | permission somebody has audited and believed.
        */
        Schema::create('product_reviews', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            /*
             * The order this review came from.
             *
             * Its presence is what makes a review "verified". A shop attached
             * to a charity is an obvious target for review spam, and "did this
             * person actually buy it" is the only cheap defence that works.
             */
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();

            $table->string('author_name', 191);
            $table->string('author_email', 191)->nullable();

            $table->unsignedTinyInteger('rating');
            $table->string('title', 191)->nullable();
            $table->text('body');

            // pending | approved | rejected | spam
            $table->string('status', 32)->default('pending');

            /*
             * Moderated before publication, not after.
             *
             * Post-moderation means the offensive review is on the foundation's
             * website until somebody notices. For an organisation whose
             * standing is most of what it has, that is the wrong way round.
             */
            $table->foreignId('moderated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('moderated_at')->nullable();
            $table->string('rejection_reason', 191)->nullable();

            $table->string('ip_address', 45)->nullable();

            $table->timestamps();
            $table->softDeletes();

            // One review per person per product. Without it, a determined
            // reviewer is a ratings average.
            $table->unique(['product_id', 'user_id'], 'product_reviews_one_per_user');
            $table->index(['product_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_reviews');
        Schema::dropIfExists('event_tickets');
        Schema::dropIfExists('sponsorship_updates');
        Schema::dropIfExists('sponsorships');
    }
};
