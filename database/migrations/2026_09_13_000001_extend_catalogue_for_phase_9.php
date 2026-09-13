<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9 — what the catalogue was still missing.
 *
 * Specifications and dimensions on the product; bulk and member prices on the
 * variant, because a price is the variant's; the file behind a digital
 * product; the product a ticket sells; related products as a pivot; and the
 * mark that says a low-stock warning has already gone out, so the shop email
 * is not told about the same tote bag every morning.
 *
 * `donations.order_id` is the other half of a "donation product": a line
 * that says "sponsor a meal" becomes a real donation when the order is paid,
 * and the donation points back at the order it came from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Label/value pairs, shown as a table on the product page.
            $table->json('specifications')->nullable()->after('description');

            // The file a digital product delivers, and how generously.
            $table->foreignId('download_media_id')->nullable()->after('featured_image_id')
                ->constrained('media')->nullOnDelete();
            $table->unsignedSmallInteger('download_limit')->default(5)->after('download_media_id');
            $table->unsignedSmallInteger('download_days')->default(30)->after('download_limit');

            // A ticket product sells one ticket type of one event.
            $table->foreignId('event_ticket_id')->nullable()->after('download_days')
                ->constrained('event_tickets')->nullOnDelete();
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->unsignedInteger('length_mm')->nullable()->after('weight_grams');
            $table->unsignedInteger('width_mm')->nullable()->after('length_mm');
            $table->unsignedInteger('height_mm')->nullable()->after('width_mm');

            // For a signed-in customer; null means the ordinary price.
            $table->unsignedBigInteger('member_price_minor')->nullable()->after('compare_at_price_minor');

            // [{"min_quantity": 10, "price_minor": 4500}, …], ascending; the
            // highest tier the quantity reaches is the unit price.
            $table->json('price_tiers')->nullable()->after('member_price_minor');

            $table->timestamp('low_stock_alerted_at')->nullable()->after('allow_backorder');
        });

        Schema::create('product_related', function (Blueprint $table) {
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('related_product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->primary(['product_id', 'related_product_id']);
        });

        /*
         * A ticket, one row per admission. The registration is the person
         * (found or made for the event and email); the tickets are what they
         * hold up at the door. `(order_item_id, seq)` is the idempotency: a
         * replayed settlement cannot issue a second set.
         */
        Schema::create('issued_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_ticket_id')->nullable()->constrained('event_tickets')->nullOnDelete();
            $table->foreignId('event_registration_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('seq')->default(1);
            $table->string('code', 24)->unique();
            $table->string('holder_name', 191);
            $table->timestamp('checked_in_at')->nullable();
            $table->foreignId('checked_in_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['order_item_id', 'seq']);
            $table->index(['event_id', 'checked_in_at']);
        });

        Schema::table('donations', function (Blueprint $table) {
            $table->foreignId('order_id')->nullable()->after('subscription_id')
                ->constrained()->nullOnDelete();
            // One donation per line, and the unique index is the idempotency:
            // a replayed settlement cannot sponsor the meal twice.
            $table->foreignId('order_item_id')->nullable()->unique()->after('order_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('donations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('order_item_id');
            $table->dropConstrainedForeignId('order_id');
        });

        Schema::dropIfExists('issued_tickets');
        Schema::dropIfExists('product_related');

        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn(['length_mm', 'width_mm', 'height_mm', 'member_price_minor', 'price_tiers', 'low_stock_alerted_at']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('download_media_id');
            $table->dropConstrainedForeignId('event_ticket_id');
            $table->dropColumn(['specifications', 'download_limit', 'download_days']);
        });
    }
};
