<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which blocks an editor may place, and in what order they appear in the picker.
 *
 * Deliberately NOT the definition of a block. A block's fields, validation and
 * Blade view live in PHP (App\Blocks\BlockRegistry) because they are code and
 * must be deployed with the code that renders them. Storing a field schema in
 * the database means the schema and the view can disagree, and the failure
 * shows up as a broken public page.
 *
 * This table holds only what an admin legitimately controls: availability and
 * ordering. Rows are projected from the registry by the seeder, so a new block
 * appears automatically on deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('block_types', function (Blueprint $table) {
            $table->id();

            // Matches a key in the PHP registry.
            $table->string('key', 64)->unique();

            $table->boolean('is_enabled')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);

            // Null = unlimited. 1 for blocks that make no sense twice on a page,
            // such as the inline donation widget.
            $table->unsignedSmallInteger('max_per_page')->nullable();

            $table->timestamps();

            $table->index(['is_enabled', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('block_types');
    }
};
