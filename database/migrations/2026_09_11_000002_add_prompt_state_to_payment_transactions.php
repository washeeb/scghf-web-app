<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8 — the state of a direct mobile-money charge.
 *
 * A charge made straight to a handset — rather than through the hosted
 * payment page — sits in one of a few waiting states while the donor does
 * something on their phone: `pay_offline` (approve the prompt), `send_otp`
 * (type the code the network texted), `send_pin`. The state and the text the
 * gateway asked us to show are kept on the transaction so the waiting page can
 * say the right thing after a refresh, on another device, or an hour later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->string('awaiting_action', 32)->nullable()->after('access_code');
            $table->string('display_text', 500)->nullable()->after('awaiting_action');
        });
    }

    public function down(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->dropColumn(['awaiting_action', 'display_text']);
        });
    }
};
