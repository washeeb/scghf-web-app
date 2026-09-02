<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Design tokens for both themes — PHASE-1-BLUEPRINT.md §5.
 *
 * Separate from `settings` because these have a dimension settings do not:
 * every token exists twice, once per theme, and the pair must be reasoned about
 * together. Squeezing that into `settings` would mean keys like
 * `theme.dark.text_primary` and no way to ask "show me this token in both
 * themes side by side", which is exactly what the admin editor needs.
 *
 * `contrast_against` + `min_contrast` are what let the editor refuse a colour
 * that breaks AA. A token declaring what it must be readable on is the only way
 * to check it automatically.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('theme_settings', function (Blueprint $table) {
            $table->id();

            $table->string('theme', 16);          // light | dark
            $table->string('token', 64);          // bg, text-primary, brand-primary...
            $table->string('category', 32);       // colour | typography | spacing | radius | shadow | motion
            $table->string('value', 191);

            $table->string('label', 191);
            $table->text('description')->nullable();

            // The token this one must be legible against, e.g. text-primary is
            // checked against bg. Null for tokens with no contrast obligation
            // (spacing, radius, a decorative divider).
            $table->string('contrast_against', 64)->nullable();

            // 4.50 normal text, 3.00 large text and non-text UI. Stored rather
            // than inferred because the same colour can carry different duties.
            $table->decimal('min_contrast', 4, 2)->nullable();

            // Set on tokens the brand depends on, so an admin cannot delete the
            // primary green and leave the theme without one.
            $table->boolean('is_locked')->default(false);

            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['theme', 'token']);
            $table->index(['theme', 'category', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('theme_settings');
    }
};
