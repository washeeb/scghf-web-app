<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 5, part 3 — orders.
 *
 * ── Shop and donations stay separate, all the way down ──────────────────────
 *
 * The compliance rule is explicit: shop sales and charitable donations have
 * separate accounting, receipts, payment types and reporting, and a charitable
 * acknowledgement is NEVER issued for a purchase.
 *
 * What is SHARED is the gateway boundary — `payment_transactions` is
 * polymorphic and an Order is a Payable, because two payment paths is how a
 * ledger diverges from the gateway.
 *
 * What is SEPARATE is everything after it. An order gets an INVOICE with its
 * own numbering series (`SCGHF-INV-2026-000012`), never a receipt number from
 * the donation series (`SCGHF-R-…`). A purchase is consideration for goods;
 * calling it a contribution would misstate the transaction to the customer and
 * to the GRA.
 *
 * ── Prices are snapshotted ──────────────────────────────────────────────────
 *
 * `order_items` carries the product name, SKU and unit price as they were.
 * Never joined to the live product for price: a price change must not
 * retroactively alter what a customer paid. That is an accounting error and a
 * trust problem at the same time.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
        |----------------------------------------------------------------------
        | Orders
        |----------------------------------------------------------------------
        */
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            // `SCGHF-O-…`, quotable over the phone. A DIFFERENT prefix from a
            // donation, so nobody reading a reference aloud can confuse the two.
            $table->string('reference', 32)->unique();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            /*
             * A shop customer is not a donor. Linking the two tables would make
             * "total donated" include merchandise, which is exactly the
             * conflation the compliance rule forbids.
             */
            $table->string('customer_name', 191);
            $table->string('customer_email', 191);
            $table->string('customer_phone', 32)->nullable();

            // pending | paid | processing | shipped | delivered | collected
            // | cancelled | refunded | needs_review
            $table->string('status', 32)->default('pending');

            // ── Money. Every column integer pesewas. ─────────────────────────
            $table->unsignedBigInteger('subtotal_minor');
            $table->unsignedBigInteger('shipping_minor')->default(0);
            $table->unsignedBigInteger('discount_minor')->default(0);
            $table->unsignedBigInteger('total_minor');
            $table->unsignedBigInteger('fee_minor')->default(0);
            $table->char('currency', 3)->default('GHS');

            $table->foreignId('coupon_id')->nullable()->constrained()->nullOnDelete();
            $table->string('coupon_code', 32)->nullable();

            // ── Delivery ─────────────────────────────────────────────────────
            $table->foreignId('shipping_zone_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('shipping_rate_id')->nullable()->constrained()->nullOnDelete();

            // Snapshotted, like the prices: a rate renamed or repriced later
            // must not change what this customer was told they were paying.
            $table->string('shipping_method', 191)->nullable();

            $table->string('delivery_name', 191)->nullable();
            $table->string('delivery_phone', 32)->nullable();
            $table->string('delivery_address')->nullable();
            $table->string('delivery_area', 191)->nullable();
            $table->string('delivery_region', 191)->nullable();
            $table->text('delivery_notes')->nullable();
            $table->boolean('is_pickup')->default(false);

            $table->string('channel', 32)->nullable();
            $table->string('paystack_reference', 191)->nullable();

            $table->timestamp('paid_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 191)->nullable();

            /*
             * Stock is held from the moment the order is created until it is
             * paid or abandoned. This is when the hold was placed, so the
             * abandonment sweep knows what to release.
             */
            $table->boolean('stock_held')->default(false);
            $table->boolean('stock_committed')->default(false);

            $table->text('notes')->nullable();

            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // No softDeletes. Append-only, like donations.

            $table->index(['status', 'created_at']);
            $table->index(['customer_email', 'created_at']);
            $table->index('paystack_reference');
            $table->index('paid_at');
        });

        /*
        |----------------------------------------------------------------------
        | Order lines — snapshots, never joins
        |----------------------------------------------------------------------
        */
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            /*
             * Nullable, and ON DELETE SET NULL. A product discontinued two years
             * later must not take the order history with it — the snapshot
             * below is what the invoice says, and it stands on its own.
             */
            $table->foreignId('product_variant_id')->nullable()
                ->constrained()->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();

            // The snapshot. This is what the invoice prints.
            $table->string('product_name', 191);
            $table->string('variant_name', 191)->nullable();
            $table->string('sku', 64);

            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('unit_price_minor');
            $table->unsignedBigInteger('line_total_minor');
            $table->char('currency', 3)->default('GHS');

            $table->unsignedInteger('weight_grams')->nullable();

            $table->timestamps();

            $table->index('order_id');
            $table->index('sku');
        });

        /*
        |----------------------------------------------------------------------
        | Status history
        |----------------------------------------------------------------------
        |
        | Append-only. "When did this ship?" and "who cancelled it?" are the two
        | questions a customer service enquiry always asks, and a single status
        | column answers neither.
        */
        Schema::create('order_status_histories', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->string('note', 191)->nullable();

            // Null means the system did it — a webhook, the abandonment sweep.
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('created_at')->nullable();

            $table->index(['order_id', 'created_at']);
        });

        /*
        |----------------------------------------------------------------------
        | Invoices — a SEPARATE series from donation acknowledgements
        |----------------------------------------------------------------------
        */
        Schema::create('invoice_sequences', function (Blueprint $table) {
            $table->unsignedSmallInteger('financial_year')->primary();
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->foreignId('order_id')->unique()->constrained()->restrictOnDelete();

            // `SCGHF-INV-2026-000012`. Never `SCGHF-R-…`: a purchase is not a
            // contribution, and the two series must never interleave.
            $table->string('invoice_number', 32)->unique();
            $table->unsignedSmallInteger('financial_year');
            $table->unsignedInteger('sequence');

            $table->date('issued_on');

            $table->string('customer_name', 191);
            $table->string('customer_email', 191)->nullable();
            $table->string('organisation_name', 191);
            $table->string('organisation_tin', 64)->nullable();

            $table->unsignedBigInteger('subtotal_minor');
            $table->unsignedBigInteger('shipping_minor')->default(0);
            $table->unsignedBigInteger('discount_minor')->default(0);
            $table->unsignedBigInteger('total_minor');
            $table->char('currency', 3)->default('GHS');
            $table->string('total_in_words', 500);

            /*
             * The line that keeps the two apart in the customer's hands as well
             * as in the ledger. Printed on every invoice.
             */
            $table->text('statement');

            $table->foreignId('pdf_media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->string('sent_to', 191)->nullable();

            $table->timestamps();

            $table->unique(['financial_year', 'sequence']);
            $table->index(['financial_year', 'issued_on']);
        });

        /*
        |----------------------------------------------------------------------
        | Digital downloads
        |----------------------------------------------------------------------
        |
        | A signed, expiring, count-limited token rather than a public URL. A
        | file on a guessable path is a file everyone has.
        */
        Schema::create('digital_download_tokens', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_id')->nullable()->constrained('media')->nullOnDelete();

            $table->string('token', 64)->unique();

            $table->unsignedSmallInteger('max_downloads')->default(5);
            $table->unsignedSmallInteger('download_count')->default(0);

            $table->timestamp('expires_at');
            $table->timestamp('last_downloaded_at')->nullable();
            $table->string('last_ip', 45)->nullable();

            $table->timestamps();

            $table->index(['order_id', 'expires_at']);
        });

        // The deferred link from a coupon redemption to its order.
        Schema::table('coupon_redemptions', function (Blueprint $table) {
            $table->foreign('order_id')->references('id')->on('orders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('coupon_redemptions', function (Blueprint $table) {
            $table->dropForeign(['order_id']);
        });

        Schema::dropIfExists('digital_download_tokens');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('invoice_sequences');
        Schema::dropIfExists('order_status_histories');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
