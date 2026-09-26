<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a picture's right to be shown comes from.
 *
 * `own` — the foundation's photography: a person in it needs a photo
 * consent on record before it can be published (Act 843, and the
 * safeguarding policy for children). That gate has existed since Phase 11.
 *
 * `stock` — a licensed picture (Unsplash, Adobe Stock, a donated agency
 * image): the people in it were released to the provider, the licence is
 * the permission, and `licence_url` records where it came from. The
 * consent gate does not apply, because there is nobody the foundation
 * could ask.
 *
 * Added when the launch photography arrived (2026-09-20): forty-odd
 * licensed pictures of people that the consent rule would otherwise have
 * hidden, correctly, from a site with nothing else to show.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table): void {
            $table->string('licence', 16)->default('own')->after('credit');
            $table->string('licence_url', 500)->nullable()->after('licence');
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table): void {
            $table->dropColumn(['licence', 'licence_url']);
        });
    }
};
