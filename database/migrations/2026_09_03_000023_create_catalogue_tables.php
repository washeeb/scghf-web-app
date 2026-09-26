<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 5, part 1 — the shop catalogue.
 *
 * ── Stock is a LEDGER, not a counter ────────────────────────────────────────
 *
 * `inventory_movements` is append-only: every change is a row with a reason
 * (`sale`, `restock`, `adjustment`, `return`, `hold`, `hold_release`), and
 * current stock is the sum. A bare `stock` integer silently loses history the
 * first time two orders race, and "we had twelve mugs yesterday and have nine
 * today" becomes unanswerable.
 *
 * `product_variants.stock_on_hand` is kept alongside as a denormalised cache so
 * a listing page does not sum a ledger per row — but it is derived, and
 * `recalculateStock()` rebuilds it from the movements whenever they disagree.
 *
 * ── Regulated goods ─────────────────────────────────────────────────────────
 *
 * A product whose name or description trips a keyword in
 * `config('compliance.shop.prohibited_keywords')` cannot be published without a
 * recorded regulatory review. Medicines, supplements, food and cosmetics are
 * FDA Ghana's business, and the foundation is not going to discover that by
 * being told off.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
        |----------------------------------------------------------------------
        | Categories — a tree
        |----------------------------------------------------------------------
        */
        Schema::create('product_categories', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->foreignId('parent_id')->nullable()
                ->constrained('product_categories')->nullOnDelete();

            $table->string('name', 191);
            $table->string('slug', 191)->unique();
            $table->text('description')->nullable();

            $table->foreignId('image_id')->nullable()->constrained('media')->nullOnDelete();

            /*
             * Marks a category as one of the approved kinds in
             * config('compliance.shop.approved_categories'). A category outside
             * that list is not forbidden — the trustees can add one — but it is
             * visibly not part of the agreed taxonomy, which is the point.
             */
            $table->string('policy_key', 64)->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['parent_id', 'sort_order']);
            $table->index('is_active');
        });

        /*
        |----------------------------------------------------------------------
        | Products
        |----------------------------------------------------------------------
        */
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->foreignId('product_category_id')->nullable()
                ->constrained()->nullOnDelete();

            // Merchandise can be tied to an appeal — a campaign T-shirt.
            $table->foreignId('cause_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('division_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name', 191);
            $table->string('slug', 191)->unique();
            $table->text('summary')->nullable();
            $table->longText('description')->nullable();

            // physical | digital. A digital product needs no shipping and is
            // delivered by a signed, expiring token rather than a courier.
            $table->string('product_type', 32)->default('physical');

            /*
             * The FDA guard.
             *
             * `requires_regulatory_review` is set automatically when a
             * prohibited keyword is found, and the product cannot be published
             * while it is true and no review has been recorded. Set by hand too,
             * for something the keyword list does not catch.
             */
            $table->boolean('requires_regulatory_review')->default(false);
            $table->text('regulatory_flags')->nullable();
            $table->string('regulatory_reference', 191)->nullable();
            $table->timestamp('regulatory_reviewed_at')->nullable();
            $table->foreignId('regulatory_reviewed_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->foreignId('featured_image_id')->nullable()->constrained('media')->nullOnDelete();

            $table->boolean('is_featured')->default(false);
            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_published', 'is_featured', 'sort_order']);
            $table->index(['product_category_id', 'is_published']);
            $table->index('requires_regulatory_review');
        });

        /*
        |----------------------------------------------------------------------
        | Variants — the thing actually bought
        |----------------------------------------------------------------------
        |
        | Every product has at least one, even one with no choices to make. A
        | nullable "default variant" special case would mean two code paths
        | through pricing, stock and order lines, and the rarely-exercised one
        | would be the wrong one — the same reasoning as donation_items.
        */
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            $table->string('sku', 64)->unique();
            $table->string('name', 191)->nullable();   // "Large / Navy"

            // Free-form option pairs — size, colour. JSON is justified here for
            // the same reason page_sections.data is: the shape genuinely varies.
            $table->json('options')->nullable();

            $table->unsignedBigInteger('price_minor');
            $table->unsignedBigInteger('compare_at_price_minor')->nullable();
            $table->char('currency', 3)->default('GHS');

            /*
             * Derived from `inventory_movements`, kept here so a listing page
             * does not sum a ledger per row. `recalculateStock()` rebuilds it.
             */
            $table->integer('stock_on_hand')->default(0);

            /*
             * Stock held for orders awaiting payment. Subtracted from what is
             * sellable, so two customers cannot buy the last mug while the
             * first is still on the payment page.
             */
            $table->unsignedInteger('stock_held')->default(0);

            // Some things genuinely are unlimited — a digital download.
            $table->boolean('tracks_stock')->default(true);
            $table->boolean('allow_backorder')->default(false);

            $table->unsignedInteger('weight_grams')->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['product_id', 'is_active', 'sort_order']);
        });

        /*
        |----------------------------------------------------------------------
        | Inventory movements — append-only
        |----------------------------------------------------------------------
        */
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();

            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();

            // sale | restock | adjustment | return | hold | hold_release | write_off
            $table->string('reason', 32);

            /*
             * SIGNED. A sale is negative, a restock positive. This is the one
             * place in the schema where a negative number is correct: it is a
             * movement, not a balance, and a movement has a direction.
             */
            $table->integer('quantity');

            // What the ledger summed to after this row, so a discrepancy can be
            // traced to the movement that introduced it rather than recomputed
            // from the beginning of time.
            $table->integer('balance_after');

            $table->string('reference', 191)->nullable();   // order reference, delivery note
            $table->text('note')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['product_variant_id', 'created_at']);
            $table->index(['reason', 'created_at']);
            $table->index('reference');
        });

        /*
        |----------------------------------------------------------------------
        | Images
        |----------------------------------------------------------------------
        */
        Schema::create('product_images', function (Blueprint $table) {
            $table->id();

            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('media_id')->constrained('media')->cascadeOnDelete();

            $table->string('alt_text', 191)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['product_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_images');
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('products');
        Schema::dropIfExists('product_categories');
    }
};
