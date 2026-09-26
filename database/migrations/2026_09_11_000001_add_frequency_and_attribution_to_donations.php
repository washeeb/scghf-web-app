<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8 — what the donation form now asks, and where the gift came from.
 *
 * `recurring_interval` is the frequency the donor chose beside
 * `wants_recurring`; intent, not state — `subscription_id` is what happened.
 * `public_message` is what a donor may leave on the donor wall. `source` and
 * `utm` are attribution: which campaign, which link, so the foundation can tell
 * whether the radio advert or the church notice raised more.
 *
 * `momo_provider` is which network a direct mobile-money charge was made to,
 * distinct from `momo_network` (derived from the phone number) because a donor
 * can pay from a number on one network for a gift recorded against another.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donations', function (Blueprint $table) {
            $table->string('recurring_interval', 16)->nullable()->after('wants_recurring');
            $table->text('public_message')->nullable()->after('tribute_notify_email');
            $table->string('source', 64)->nullable()->after('notes');
            $table->json('utm')->nullable()->after('source');
            $table->string('momo_provider', 16)->nullable()->after('momo_network');

            $table->index('source');
        });
    }

    public function down(): void
    {
        Schema::table('donations', function (Blueprint $table) {
            $table->dropIndex(['source']);
            $table->dropColumn(['recurring_interval', 'public_message', 'source', 'utm', 'momo_provider']);
        });
    }
};
