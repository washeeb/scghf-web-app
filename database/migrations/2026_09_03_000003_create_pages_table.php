<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CMS pages, composed from ordered blocks.
 *
 * See PHASE-1-BLUEPRINT.md §6.1 for the sitemap these seed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->foreignId('parent_id')->nullable()
                ->constrained('pages')->nullOnDelete();

            $table->string('title', 191);
            $table->string('slug', 191);

            /*
             * The full path, e.g. '/about/leadership'. Unique, and the column
             * the router actually looks up.
             *
             * Why not just UNIQUE(parent_id, slug)? Because in MySQL a unique
             * index permits unlimited NULLs, and every top-level page has
             * parent_id = NULL — so that constraint would happily allow two
             * pages both at '/about'. The bug only appears at the root, which
             * is exactly where it hurts most.
             *
             * A materialised path also turns route resolution into one indexed
             * equality lookup instead of walking the tree per request.
             * Maintained by the model whenever slug or parent changes.
             */
            $table->string('path', 191)->unique();

            $table->text('excerpt')->nullable();

            // Which Blade layout renders it: 'default', 'full-width',
            // 'sidebar', 'landing'. VARCHAR + PHP-side registry, not an ENUM.
            $table->string('template', 64)->default('default');

            $table->string('status', 32)->default('draft');
            $table->timestamp('published_at')->nullable();

            // Exactly one page answers '/'. Enforced by the model, since a
            // partial unique index is not portable across MySQL and MariaDB.
            $table->boolean('is_homepage')->default(false);

            /*
             * System pages the application routes to by name — /donate,
             * /contact, the legal pages. An admin may edit the content freely
             * but must not delete the page or change its path, or the footer
             * links and Paystack callbacks break.
             */
            $table->boolean('is_locked')->default(false);

            $table->boolean('show_in_sitemap')->default(true);
            $table->boolean('show_in_search')->default(true);

            // Cheap ordering for sibling pages in navigation pickers.
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // The public query: published pages, live now.
            $table->index(['status', 'published_at']);
            $table->index(['parent_id', 'sort_order']);
            $table->index('is_homepage');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pages');
    }
};
