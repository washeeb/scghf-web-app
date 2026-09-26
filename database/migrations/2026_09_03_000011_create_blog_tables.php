<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blog_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 191);
            $table->string('slug', 191)->unique();
            $table->text('description')->nullable();
            $table->string('colour', 16)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_published')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->foreignId('blog_category_id')->nullable()
                ->constrained()->nullOnDelete();

            // The byline. Nullable so a departing author does not take their
            // posts with them — ON DELETE SET NULL, per §1.4.
            $table->foreignId('author_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->string('title', 191);
            $table->string('slug', 191)->unique();
            $table->text('excerpt')->nullable();
            $table->longText('body')->nullable();

            $table->foreignId('featured_image_id')->nullable()
                ->constrained('media')->nullOnDelete();

            // Same lifecycle as pages, same enum.
            $table->string('status', 32)->default('draft');
            $table->timestamp('published_at')->nullable();

            $table->boolean('is_featured')->default(false);
            $table->boolean('allow_comments')->default(true);

            // Denormalised so an index listing does not run a COUNT per row.
            // Maintained in the same transaction as the comment.
            $table->unsignedInteger('comment_count')->default(0);
            $table->unsignedInteger('view_count')->default(0);

            // Rough reading time in minutes, computed on save. Small courtesy
            // that matters more on a slow connection, where a visitor is
            // deciding whether to spend the data.
            $table->unsignedSmallInteger('reading_minutes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // The public index: live posts, newest first.
            $table->index(['status', 'published_at']);
            $table->index(['blog_category_id', 'status']);
            $table->index('is_featured');
        });

        // Tags are polymorphic from the start: projects, causes and products
        // all want them, and retrofitting a pivot later means migrating data.
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name', 96);
            $table->string('slug', 96)->unique();
            $table->unsignedInteger('usage_count')->default(0);
            $table->timestamps();
        });

        Schema::create('taggables', function (Blueprint $table) {
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->morphs('taggable');

            $table->primary(['tag_id', 'taggable_id', 'taggable_type'], 'taggables_primary');
        });

        /*
         * Comments, moderated.
         *
         * Behind FEATURE_BLOG_COMMENTS, off by default. An unmoderated comment
         * form on a foundation's site is a spam target and a safeguarding
         * surface, so nothing appears without approval.
         */
        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->foreignId('post_id')->constrained()->cascadeOnDelete();

            // Threaded replies, one level.
            $table->foreignId('parent_id')->nullable()
                ->constrained('comments')->cascadeOnDelete();

            // Null for a guest comment; set when a signed-in user comments.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('author_name', 191);
            $table->string('author_email', 191);
            $table->text('body');

            // pending | approved | spam | rejected
            $table->string('status', 32)->default('pending');

            // Evidence for moderation and, if it ever comes to it, for a report.
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            $table->foreignId('moderated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('moderated_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['post_id', 'status', 'created_at']);
            $table->index('status');
            $table->index('author_email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comments');
        Schema::dropIfExists('taggables');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('posts');
        Schema::dropIfExists('blog_categories');
    }
};
