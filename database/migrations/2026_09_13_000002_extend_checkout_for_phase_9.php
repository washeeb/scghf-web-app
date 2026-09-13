<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9 — the checkout, completed.
 *
 * The rest of a Ghanaian address: the district or town, the landmark a
 * courier asks for, and the GhanaPost GPS digital address. Where a
 * collection point actually is. The gift a customer adds to an order at
 * the last step, kept apart from the goods so the invoice stays an invoice
 * and the receipt stays a receipt. And the mark that says the abandoned
 * checkout reminder has gone, so it goes once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('delivery_city', 191)->nullable()->after('delivery_area');
            $table->string('delivery_landmark', 191)->nullable()->after('delivery_region');
            $table->string('delivery_gps', 16)->nullable()->after('delivery_landmark');

            // Added at checkout, in pesewas; becomes a donation when paid.
            $table->unsignedBigInteger('donation_minor')->default(0)->after('discount_minor');

            $table->timestamp('reminded_at')->nullable()->after('cancel_reason');
        });

        Schema::table('shipping_zones', function (Blueprint $table) {
            $table->string('pickup_address', 255)->nullable()->after('is_pickup');
            $table->string('pickup_hours', 191)->nullable()->after('pickup_address');
            $table->string('pickup_phone', 32)->nullable()->after('pickup_hours');
        });
    }

    public function down(): void
    {
        Schema::table('shipping_zones', function (Blueprint $table) {
            $table->dropColumn(['pickup_address', 'pickup_hours', 'pickup_phone']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['delivery_city', 'delivery_landmark', 'delivery_gps', 'donation_minor', 'reminded_at']);
        });
    }
};
