<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Make this monthly", carried from the form to the webhook.
 *
 * ── Why the intent has to be stored ─────────────────────────────────────────
 *
 * A subscription is established from a gift that ACTUALLY WENT THROUGH —
 * `RecurringGivingService::establish()` refuses anything else, and rightly: a
 * standing order set up from a payment that was later declined is a monthly
 * charge against a card that never worked.
 *
 * But completion happens in the webhook, minutes after the donor has left the
 * browser. By then the checkbox they ticked is gone unless it was written down.
 * So the intent is stored with the donation and read when the payment settles.
 *
 * ── Intent, not state ───────────────────────────────────────────────────────
 *
 * This column says what the donor ASKED for. `donations.subscription_id` says
 * what actually happened. They are deliberately separate: a gift that completed
 * but could not establish a subscription — a mobile-money authorization that is
 * not reusable, say — leaves the intent recorded and the subscription null,
 * which is exactly the pair somebody needs to see to follow it up.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donations', function (Blueprint $table): void {
            $table->boolean('wants_recurring')->default(false)->after('fee_covered_by_donor');
        });
    }

    public function down(): void
    {
        Schema::table('donations', function (Blueprint $table): void {
            $table->dropColumn('wants_recurring');
        });
    }
};
