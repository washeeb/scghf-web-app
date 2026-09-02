<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Folders for the media library, plus the column that puts a media row in one.
 *
 * spatie/laravel-medialibrary has no concept of folders — its `media` table is
 * flat and keyed to whatever model owns the file. That is fine for "this
 * project's gallery" but useless for "find the logo I uploaded in March", which
 * is what staff actually need. This adds organisation without touching how
 * Media Library stores or converts anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_folders', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->foreignId('parent_id')->nullable()
                ->constrained('media_folders')->cascadeOnDelete();

            $table->string('name', 191);
            $table->string('slug', 191);

            // Materialised path, same reasoning as pages: MySQL allows multiple
            // NULLs in a unique index, so UNIQUE(parent_id, slug) would permit
            // two root folders both called "Logos".
            $table->string('path', 500)->unique();

            $table->text('description')->nullable();

            // Folders the application writes into — 'Brand', 'Beneficiaries'.
            // The Beneficiaries folder in particular carries consent rules, so
            // it must not be renamed out from under the code that checks them.
            $table->boolean('is_locked')->default(false);

            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['parent_id', 'sort_order']);
        });

        Schema::table('media', function (Blueprint $table) {
            // Nullable: a file uploaded straight onto a model has no folder,
            // and that is a legitimate state rather than an error.
            $table->foreignId('folder_id')->nullable()->after('id')
                ->constrained('media_folders')->nullOnDelete();

            // Alt text is required before publishing by the CMS layer, not by
            // the database — a file can exist before someone writes its alt
            // text, but it cannot be placed on a page without one.
            $table->string('alt_text', 500)->nullable()->after('name');
            $table->string('caption', 500)->nullable()->after('alt_text');
            $table->string('credit', 191)->nullable()->after('caption');

            $table->index('folder_id');
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->dropConstrainedForeignId('folder_id');
            $table->dropColumn(['alt_text', 'caption', 'credit']);
        });

        Schema::dropIfExists('media_folders');
    }
};
