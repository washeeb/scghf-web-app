<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One block placed on one page.
 *
 * `data` is JSON, and this is the one place in the schema where that is the
 * right call rather than a concession: a hero's fields and an FAQ accordion's
 * fields genuinely have nothing in common, and modelling every block's payload
 * as its own table would mean a migration every time a block gains a field.
 * Everything else in this project gets a real table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_sections', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->foreignId('page_id')->constrained()->cascadeOnDelete();

            // The registry key. No FK to block_types: the registry is the source
            // of truth, and a block disabled in the admin must not orphan
            // sections already placed on a live page.
            $table->string('block_type', 64);

            // Editor-facing label so a page of six blocks is navigable in the
            // admin. Falls back to the block's own name when empty.
            $table->string('name', 191)->nullable();

            $table->json('data')->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);

            // Hide a block without deleting it — the usual way seasonal content
            // is parked between campaigns.
            $table->boolean('is_visible')->default(true);

            // Optional scheduling for time-boxed content, e.g. an appeal banner.
            $table->timestamp('visible_from')->nullable();
            $table->timestamp('visible_until')->nullable();

            $table->timestamps();

            // The render query: one page's visible blocks in order.
            $table->index(['page_id', 'sort_order']);
            $table->index('block_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_sections');
    }
};
