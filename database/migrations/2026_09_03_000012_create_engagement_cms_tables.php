<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Promotional content ──────────────────────────────────────────────
        // Banners, announcements and popups are one table: they differ only in
        // where they render, and three near-identical tables would mean three
        // admin screens for what staff think of as one job.
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            // banner | announcement_bar | popup
            $table->string('placement', 32)->default('announcement_bar');

            $table->string('title', 191);
            $table->text('body')->nullable();
            $table->string('cta_label', 64)->nullable();
            $table->string('cta_url', 500)->nullable();

            $table->foreignId('image_id')->nullable()->constrained('media')->nullOnDelete();

            // info | success | warning | urgent
            $table->string('style', 32)->default('info');

            $table->boolean('is_dismissible')->default(true);

            // How long a dismissal sticks. Blueprint §3.2: a popup that returns
            // on every visit is the fastest way to lose a donor.
            $table->unsignedSmallInteger('dismiss_days')->default(30);

            // Where it appears. Null = everywhere.
            $table->json('show_on_paths')->nullable();

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            $table->unsignedInteger('impressions')->default(0);
            $table->unsignedInteger('clicks')->default(0);

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(false);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['placement', 'is_active', 'starts_at']);
        });

        // ── Contact ──────────────────────────────────────────────────────────
        Schema::create('contact_departments', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('name', 191);
            $table->text('description')->nullable();

            // Where enquiries for this department are delivered.
            $table->string('email', 191);

            /*
             * A safeguarding report must not appear in the general admin inbox
             * alongside shop queries. Flagged departments route only to their
             * designated contact and are excluded from the normal listing.
             */
            $table->boolean('is_confidential')->default(false);

            // Hours, for the "we aim to reply within" line.
            $table->unsignedSmallInteger('sla_hours')->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('contact_messages', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            // Quotable back to the sender in the auto-reply.
            $table->string('reference', 32)->unique();

            $table->foreignId('contact_department_id')->nullable()
                ->constrained()->nullOnDelete();

            $table->string('name', 191);
            $table->string('email', 191);
            $table->string('phone', 20)->nullable();
            $table->string('subject', 191)->nullable();
            $table->text('message');

            // new | assigned | replied | resolved | spam
            $table->string('status', 32)->default('new');

            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('replied_at')->nullable();
            $table->foreignId('replied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('internal_notes')->nullable();

            // Act 843 evidence, same shape as everywhere else in this project.
            $table->boolean('consent_given')->default(false);
            $table->text('consent_text')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('source_url', 500)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'created_at']);
            $table->index(['contact_department_id', 'status']);
            $table->index('email');
        });

        // ── Newsletter ───────────────────────────────────────────────────────
        Schema::create('subscribers', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->string('email', 191)->unique();
            $table->string('name', 191)->nullable();

            // pending | confirmed | unsubscribed | bounced | complained
            $table->string('status', 32)->default('pending');

            /*
             * Double opt-in. Blueprint risk DEL-5: single opt-in is the fastest
             * way to destroy a sending domain's reputation, and a foundation
             * whose receipts stop arriving has a much bigger problem than a
             * smaller mailing list.
             */
            $table->string('confirmation_token', 64)->nullable()->unique();
            $table->timestamp('confirmed_at')->nullable();

            // One-click, no login, no confirmation page. Also drives the
            // List-Unsubscribe header.
            $table->string('unsubscribe_token', 64)->unique();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->string('unsubscribe_reason', 191)->nullable();

            /*
             * The consent record. Act 843 requires consent to be freely given,
             * specific and informed — which means being able to show WHAT was
             * agreed to, WHEN and FROM WHERE, not merely that a row exists.
             */
            $table->text('consent_text')->nullable();
            $table->string('consent_ip', 45)->nullable();
            $table->string('consent_source_url', 500)->nullable();
            $table->timestamp('consent_at')->nullable();

            // footer | popup | donation_form | volunteer_form | import | admin
            $table->string('source', 32)->default('footer');

            // Topic preferences, so someone can take the impact update without
            // the fundraising appeals.
            $table->json('topics')->nullable();

            $table->timestamp('last_emailed_at')->nullable();
            $table->unsignedInteger('bounce_count')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'created_at']);
            $table->index('source');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscribers');
        Schema::dropIfExists('contact_messages');
        Schema::dropIfExists('contact_departments');
        Schema::dropIfExists('announcements');
    }
};
