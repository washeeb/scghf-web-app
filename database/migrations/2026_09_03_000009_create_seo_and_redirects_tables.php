<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Per-entity SEO overrides. Polymorphic across pages, posts, projects,
         * causes, products and divisions.
         *
         * A separate table rather than columns on each entity: six content
         * types would otherwise carry the same eight columns, and adding a
         * ninth would be six migrations. Every field is nullable because the
         * resolver falls back to the entity's own title/excerpt and then to the
         * `seo` settings group — a page with no overrides still emits complete
         * metadata.
         */
        Schema::create('seo_meta', function (Blueprint $table) {
            $table->id();

            $table->morphs('seoable');

            $table->string('title', 191)->nullable();
            $table->string('description', 500)->nullable();

            // Historically a ranking factor, now essentially ignored. Kept
            // because staff expect the field, not because it does anything.
            $table->string('keywords', 500)->nullable();

            $table->string('canonical_url', 500)->nullable();

            $table->string('og_title', 191)->nullable();
            $table->string('og_description', 500)->nullable();
            $table->foreignId('og_image_id')->nullable()->constrained('media')->nullOnDelete();
            $table->string('og_type', 32)->default('website');

            $table->string('twitter_card', 32)->default('summary_large_image');

            // noindex for thin pages; nofollow rarely, but the field is here so
            // an SEO consultant does not need a developer.
            $table->boolean('no_index')->default(false);
            $table->boolean('no_follow')->default(false);

            // sitemap.xml hints.
            $table->string('change_frequency', 16)->nullable();
            $table->decimal('priority', 2, 1)->nullable();

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // One row per entity.
            $table->unique(['seoable_type', 'seoable_id'], 'seo_meta_seoable_unique');
        });

        /*
         * Redirects, both hand-written and captured from 404s.
         *
         * Blueprint §6 calls for a 404-to-redirect workflow: the site records
         * paths that 404, and an editor turns the ones that matter into
         * redirects. That is how a content migration stops bleeding traffic.
         */
        Schema::create('redirects', function (Blueprint $table) {
            $table->id();

            // 191, not 500. This column is UNIQUE, and in utf8mb4 a 500-char
            // index is 2000 bytes — most of InnoDB's 3072-byte budget spent on
            // a path longer than any real URL. §1.6 of the architecture doc.
            $table->string('from_path', 191);

            // Not indexed, so length is free here.
            $table->string('to_path', 500)->nullable();

            // 301 permanent, 302 temporary, 410 gone. Integer rather than a
            // string: it goes straight into the HTTP response.
            $table->unsignedSmallInteger('status_code')->default(301);

            // manual | import | auto_404
            $table->string('source', 32)->default('manual');

            $table->boolean('is_active')->default(true);

            // Whether the query string is carried across. Off by default —
            // preserving a stale ?utm_ on a redirect pollutes analytics.
            $table->boolean('preserve_query')->default(false);

            // Usage, so an editor can see which redirects still matter and
            // which 404s are worth fixing.
            $table->unsignedInteger('hits')->default(0);
            $table->timestamp('last_hit_at')->nullable();

            // For auto_404 rows: where the visitor came from, which is usually
            // the clue to what the path should have been.
            $table->string('last_referrer', 500)->nullable();

            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('from_path');
            $table->index(['is_active', 'source']);
            $table->index('hits');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('redirects');
        Schema::dropIfExists('seo_meta');
    }
};
