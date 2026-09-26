<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a block LOOKS, as opposed to what it says.
 *
 * `data` holds the block's content — its heading, its image, its list of
 * items — and is shaped by the block's own field definition. This holds the
 * presentation choices every block shares: background, padding, alignment,
 * container width, light or dark variant, and which screen sizes it appears on.
 *
 * ── Why a second column rather than more keys in `data` ─────────────────────
 *
 * Because they have different lifetimes and different owners.
 *
 * `data` is validated against `BlockDefinition::validationRules()`, which is
 * generated from the field list that ships with the block's Blade view. Adding
 * presentation keys there would mean every one of the twenty definitions
 * repeating the same eight fields, and a block added in a year silently missing
 * whichever ones its author forgot.
 *
 * Keeping them apart also means a block's content survives a change to how
 * blocks are styled, and the styling vocabulary can grow without touching a
 * single block definition or re-validating anybody's content.
 *
 * NULL is a legitimate and common value: it means "the defaults", which is what
 * `App\Blocks\SectionSettings` returns for a section nobody has styled. Every
 * section that existed before this migration is in exactly that state, which is
 * correct rather than a backfill waiting to happen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('page_sections', function (Blueprint $table) {
            $table->json('settings')->nullable()->after('data');
        });
    }

    public function down(): void
    {
        Schema::table('page_sections', function (Blueprint $table) {
            $table->dropColumn('settings');
        });
    }
};
