<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The reusable content types blocks draw from.
 *
 * These are grouped in one migration because they share an identical shape —
 * a title, a body, an image, ordering and a publish flag — and splitting six
 * near-identical 30-line migrations across six files makes them harder to
 * compare, not easier.
 *
 * A note on `division_id`: several of these will be scoped to one of the four
 * divisions, but `divisions` does not exist until Module 3. Adding an
 * unconstrained integer now would be a foreign key in all but name, with none
 * of the integrity. The column and its constraint arrive together in Module 3,
 * which is what expand-only migrations are for.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── FAQs ─────────────────────────────────────────────────────────────
        Schema::create('faq_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 191);
            $table->string('slug', 191)->unique();
            $table->text('description')->nullable();
            $table->string('icon', 64)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_published')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('faqs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('faq_category_id')->nullable()
                ->constrained()->nullOnDelete();

            $table->string('question', 500);
            $table->text('answer');

            // How often this answer is actually read. A question nobody opens
            // is a question the page does not need.
            $table->unsignedInteger('view_count')->default(0);

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_published')->default(true);
            $table->boolean('is_featured')->default(false);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['faq_category_id', 'sort_order']);
            $table->index(['is_published', 'sort_order']);
        });

        // ── Testimonials ─────────────────────────────────────────────────────
        Schema::create('testimonials', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->string('author_name', 191);
            $table->string('author_role', 191)->nullable();
            $table->string('author_location', 191)->nullable();
            $table->text('quote');

            // beneficiary | volunteer | partner | donor | staff
            $table->string('author_type', 32)->default('beneficiary');

            $table->foreignId('photo_id')->nullable()->constrained('media')->nullOnDelete();

            /*
             * A testimonial from a beneficiary is a story about a real person,
             * often a vulnerable one, and it needs the same consent as their
             * photograph. `consents` arrives with beneficiaries in Module 3;
             * this flag is the gate in the meantime, and publication is blocked
             * without it.
             */
            $table->boolean('has_consent')->default(false);
            $table->date('consent_date')->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_published')->default(false);
            $table->boolean('is_featured')->default(false);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_published', 'sort_order']);
        });

        // ── Partners ─────────────────────────────────────────────────────────
        Schema::create('partners', function (Blueprint $table) {
            $table->id();
            $table->string('name', 191);
            $table->string('slug', 191)->unique();
            $table->text('description')->nullable();
            $table->string('website_url', 500)->nullable();

            $table->foreignId('logo_id')->nullable()->constrained('media')->nullOnDelete();

            // church | company | ngo | institution | government | individual
            $table->string('partner_type', 32)->default('organisation');

            $table->date('partnership_started_on')->nullable();
            $table->date('partnership_ended_on')->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_published')->default(true);
            $table->boolean('is_featured')->default(false);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_published', 'sort_order']);
        });

        // ── People ───────────────────────────────────────────────────────────
        Schema::create('team_departments', function (Blueprint $table) {
            $table->id();
            $table->string('name', 191);
            $table->string('slug', 191)->unique();
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_published')->default(true);
            $table->timestamps();
        });

        Schema::create('team_members', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->foreignId('team_department_id')->nullable()
                ->constrained()->nullOnDelete();

            // A trustee is not necessarily a system user, and a system user is
            // not necessarily on the public page. Linking them is optional.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name', 191);
            $table->string('slug', 191)->unique();
            $table->string('role_title', 191);
            $table->text('bio')->nullable();

            $table->foreignId('photo_id')->nullable()->constrained('media')->nullOnDelete();

            // Optional, and separate from the person's private contact details.
            // Publishing a trustee's personal mobile is a real risk.
            $table->string('public_email', 191)->nullable();
            $table->string('linkedin_url', 500)->nullable();

            // trustee | leadership | staff | volunteer | advisor
            $table->string('member_type', 32)->default('staff');

            $table->boolean('is_trustee')->default(false);
            $table->date('joined_on')->nullable();
            $table->date('left_on')->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_published')->default(false);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['team_department_id', 'sort_order']);
            $table->index(['is_published', 'sort_order']);
        });

        // ── Galleries ────────────────────────────────────────────────────────
        Schema::create('galleries', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->string('title', 191);
            $table->string('slug', 191)->unique();
            $table->text('description')->nullable();

            $table->foreignId('cover_id')->nullable()->constrained('media')->nullOnDelete();

            $table->date('taken_on')->nullable();
            $table->string('location', 191)->nullable();

            // Same reasoning as testimonials: a gallery of beneficiaries needs
            // consent before it is public.
            $table->boolean('has_consent')->default(false);

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_published')->default(false);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_published', 'sort_order']);
        });

        Schema::create('gallery_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gallery_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_id')->constrained('media')->cascadeOnDelete();

            $table->string('caption', 500)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->unique(['gallery_id', 'media_id']);
            $table->index(['gallery_id', 'sort_order']);
        });

        // ── Documents ────────────────────────────────────────────────────────
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->string('title', 191);
            $table->string('slug', 191)->unique();
            $table->text('description')->nullable();

            $table->foreignId('media_id')->nullable()->constrained('media')->nullOnDelete();

            // annual_report | policy | form | brochure | financial | other
            $table->string('document_type', 32)->default('other');

            // Which financial or programme year it covers, for the
            // transparency page's grouping.
            $table->unsignedSmallInteger('year')->nullable();

            /*
             * Some documents are public (annual report), some are for staff
             * only (an internal policy). A document behind this flag is served
             * through an authorised controller, never a public storage URL.
             */
            $table->boolean('requires_auth')->default(false);

            $table->unsignedInteger('download_count')->default(0);

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_published')->default(false);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['document_type', 'year']);
            $table->index(['is_published', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
        Schema::dropIfExists('gallery_items');
        Schema::dropIfExists('galleries');
        Schema::dropIfExists('team_members');
        Schema::dropIfExists('team_departments');
        Schema::dropIfExists('partners');
        Schema::dropIfExists('testimonials');
        Schema::dropIfExists('faqs');
        Schema::dropIfExists('faq_categories');
    }
};
