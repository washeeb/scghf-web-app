<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 5, part 2 — carts, shipping and coupons.
 *
 * ── Carts are not orders ────────────────────────────────────────────────────
 *
 * A cart holds a variant id and a quantity, and nothing else. It deliberately
 * does NOT snapshot a price: a cart is a wish, and showing a stale price from
 * three weeks ago at checkout would be the wrong number in the direction that
 * annoys a customer or loses the foundation money. Prices are read live from
 * the variant until the order is placed, at which point they are frozen onto
 * the order line for good.
 *
 * ── Shipping is per region, because Ghana is ────────────────────────────────
 *
 * `shipping_zones` group the sixteen regions; `shipping_rates` price them, with
 * an optional free-delivery threshold. Pickup is a zone with a zero rate rather
 * than a special case, so the checkout has one code path.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
        |----------------------------------------------------------------------
        | Shipping
        |----------------------------------------------------------------------
        */
        Schema::create('shipping_zones', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->string('name', 191);
            $table->string('slug', 191)->unique();
            $table->text('description')->nullable();

            /*
             * The regions this zone covers, as a JSON list. Justified over a
             * pivot table: the list is short, fixed at sixteen, never joined
             * against, and only ever read whole.
             */
            $table->json('regions')->nullable();

            // A pickup zone is a zone with a zero rate, not a special case —
            // one code path through the checkout.
            $table->boolean('is_pickup')->default(false);

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });

        Schema::create('shipping_rates', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shipping_zone_id')->constrained()->cascadeOnDelete();

            $table->string('name', 191);   // "Standard", "Express"
            $table->unsignedBigInteger('price_minor');
            $table->char('currency', 3)->default('GHS');

            /*
             * Above this order value the rate is free. Nullable rather than a
             * huge sentinel, because "no free delivery on this rate" and
             * "free delivery above GH₵ 1,000,000" are different statements and
             * only one of them is honest.
             */
            $table->unsignedBigInteger('free_above_minor')->nullable();

            // Weight banding, both nullable — a rate with neither applies to
            // any basket.
            $table->unsignedInteger('min_weight_grams')->nullable();
            $table->unsignedInteger('max_weight_grams')->nullable();

            $table->string('estimated_days', 64)->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['shipping_zone_id', 'is_active', 'sort_order']);
        });

        /*
        |----------------------------------------------------------------------
        | Coupons
        |----------------------------------------------------------------------
        */
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            // Uppercased on save, so FRIENDS10 and friends10 are one coupon and
            // a customer typing either gets the discount.
            $table->string('code', 32)->unique();
            $table->string('description', 191)->nullable();

            // percentage | fixed | free_shipping
            $table->string('discount_type', 32)->default('percentage');

            // Basis points for a percentage (1000 = 10%), pesewas for a fixed
            // amount. One column, because a coupon is only ever one of the two.
            $table->unsignedInteger('discount_value');

            $table->unsignedBigInteger('minimum_spend_minor')->nullable();

            /*
             * A ceiling on a percentage discount. Without it, "20% off" on an
             * unusually large order is a number nobody signed off.
             */
            $table->unsignedBigInteger('maximum_discount_minor')->nullable();

            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('usage_limit_per_customer')->nullable();
            $table->unsignedInteger('times_used')->default(0);

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'starts_at', 'expires_at']);
        });

        /*
        |----------------------------------------------------------------------
        | Redemptions — one row per use
        |----------------------------------------------------------------------
        |
        | Its own table rather than a counter alone, so "who used this and when"
        | is answerable and a per-customer limit can be enforced. `times_used`
        | on the coupon is the cache.
        */
        Schema::create('coupon_redemptions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();

            // Set once the order exists. Nullable so a redemption can be
            // reserved during checkout before the order is written.
            $table->unsignedBigInteger('order_id')->nullable();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_email', 191)->nullable();

            $table->unsignedBigInteger('discount_minor');
            $table->char('currency', 3)->default('GHS');

            $table->timestamps();

            $table->index(['coupon_id', 'customer_email']);
            $table->index('order_id');
        });

        /*
        |----------------------------------------------------------------------
        | Carts
        |----------------------------------------------------------------------
        */
        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            // A guest cart is keyed on the session; a signed-in one on the user.
            // Both, briefly, when a guest signs in and the two are merged.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('session_token', 64)->nullable()->unique();

            $table->foreignId('coupon_id')->nullable()->constrained()->nullOnDelete();

            $table->string('customer_email', 191)->nullable();

            /*
             * Carts are swept after this. An abandoned cart holding a coupon or
             * a customer's email is personal data with no purpose left, and
             * Act 843 says data is kept only as long as it is needed.
             */
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();

            $table->index('user_id');
            $table->index('expires_at');
        });

        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('cart_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('quantity');

            $table->timestamps();

            // One line per variant. Adding the same mug twice increments the
            // quantity rather than making a second line nobody expects.
            $table->unique(['cart_id', 'product_variant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('carts');
        Schema::dropIfExists('coupon_redemptions');
        Schema::dropIfExists('coupons');
        Schema::dropIfExists('shipping_rates');
        Schema::dropIfExists('shipping_zones');
    }
};
