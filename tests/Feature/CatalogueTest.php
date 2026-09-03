<?php

declare(strict_types=1);

use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\User;
use App\Shop\RegulatoryScreener;
use Database\Seeders\ShopCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The shop catalogue
|--------------------------------------------------------------------------
|
| Two things these tests hold:
|
|   1. a product that looks like a regulated good cannot be published until
|      somebody with authority has recorded a review of it
|   2. stock is a LEDGER — every change has a row and a reason, and the cached
|      figure can always be rebuilt from it
|
| The first matters because the realistic failure is not somebody deliberately
| listing a drug. It is a volunteer listing "hand sanitiser for the outreach"
| and nobody noticing until a regulator does.
|
*/

beforeEach(function () {
    $this->screener = app(RegulatoryScreener::class);
    $this->staff = User::factory()->staff()->create();
});

// ═══════════════════════════════════════════════════════════════════════════
//  The agreed taxonomy
// ═══════════════════════════════════════════════════════════════════════════

it('seeds the shop taxonomy from the compliance policy, not from a second list', function () {
    // Policy and data cannot drift if only one of them exists.
    $this->seed(ShopCategorySeeder::class);

    $expected = array_keys(config('compliance.shop.approved_categories'));
    sort($expected);

    expect(ProductCategory::pluck('policy_key')->sort()->values()->all())->toBe($expected);
});

it('seeds categories inactive, because an empty category is a dead end', function () {
    $this->seed(ShopCategorySeeder::class);

    expect(ProductCategory::where('is_active', true)->count())->toBe(0);
});

it('is idempotent', function () {
    $this->seed(ShopCategorySeeder::class);
    $this->seed(ShopCategorySeeder::class);

    expect(ProductCategory::count())->toBe(count(config('compliance.shop.approved_categories')));
});

it('records the policy example items so an editor can see the scope', function () {
    $this->seed(ShopCategorySeeder::class);

    expect(ProductCategory::where('policy_key', 'apparel')->first()->description)
        ->toContain('Branded T-shirts')
        ->and(ProductCategory::where('policy_key', 'apparel')->first()->policyItems())
        ->toContain('Caps');
});

it('marks a category outside the agreed taxonomy as such', function () {
    // Not forbidden — the trustees can sell what they decide to. Just visibly
    // outside what was agreed.
    $inside = ProductCategory::factory()->create(['policy_key' => 'gifts']);
    $outside = ProductCategory::factory()->outsidePolicy()->create();

    expect($inside->isApproved())->toBeTrue()
        ->and($outside->isApproved())->toBeFalse()
        ->and(ProductCategory::query()->outsidePolicy()->pluck('id')->all())->toBe([$outside->id]);
});

// ═══════════════════════════════════════════════════════════════════════════
//  Regulated goods
// ═══════════════════════════════════════════════════════════════════════════

it('flags a product that mentions a regulated good', function (string $text) {
    expect($this->screener->isFlagged($text))->toBeTrue("[{$text}] should have been flagged");
})->with([
    'Herbal remedy for daily wellbeing',
    'Vitamin C supplement, 60 capsules',
    'Hand sanitiser, 250ml',
    'Branded thermometer',
    'Shea body cream',
]);

it('matches on word boundaries, so ordinary words do not trip it', function () {
    // "creamery" is not "cream"; "drugstore" is.
    expect($this->screener->isFlagged('Creamery tote bag'))->toBeFalse()
        ->and($this->screener->isFlagged('Drug awareness notebook'))->toBeTrue();
});

it('leaves ordinary merchandise alone', function (string $text) {
    expect($this->screener->isFlagged($text))->toBeFalse();
})->with([
    'Branded polo shirt, navy',
    'Reusable water bottle, stainless steel',
    'A5 notebook with the foundation logo',
    'Christian devotional, paperback',
]);

it('refuses to publish a flagged product until a review is recorded', function () {
    Product::factory()->regulated()->create()->publish();
})->throws(RuntimeException::class, 'regulated product');

it('publishes once the review is on file', function () {
    $product = Product::factory()->regulated()->create();

    expect($product->needsRegulatoryReview())->toBeTrue();

    $product->recordRegulatoryReview($this->staff, 'FDA/GH/2026/0142');
    $product->fresh()->publish();

    expect($product->fresh()->isLive())->toBeTrue();
});

it('insists a review carries a reference', function () {
    // "We looked at it" is not a review, and an auditor asking which FDA
    // correspondence covers this product needs an answer.
    Product::factory()->regulated()->create()->recordRegulatoryReview($this->staff, '');
})->throws(RuntimeException::class, 'needs a reference');

it('re-screens on every save, not only on creation', function () {
    // The realistic failure: an approved mug whose description is later edited
    // to mention a supplement.
    $product = Product::factory()->create();

    expect($product->requires_regulatory_review)->toBeFalse();

    $product->update(['description' => 'Now includes a free vitamin supplement sachet.']);

    expect($product->fresh()->requires_regulatory_review)->toBeTrue();
});

it('takes a newly flagged product off the shop rather than refusing the edit', function () {
    /*
     * Refusing the save would throw away the editor's work and leave the older
     * text live, which looks like the edit simply failed. Unpublishing keeps
     * the text, takes it off the shop, and leaves a flag somebody must clear.
     */
    $product = Product::factory()->create();

    expect($product->is_published)->toBeTrue();

    $product->update(['description' => 'Contains a herbal remedy.']);

    expect($product->fresh()->is_published)->toBeFalse()
        ->and($product->fresh()->description)->toContain('herbal remedy')
        ->and($product->fresh()->needsRegulatoryReview())->toBeTrue();
});

it('clears an existing review when a new flag appears', function () {
    // Approving "notebook" does not approve the "supplement" somebody added to
    // it afterwards.
    $product = Product::factory()->regulated()->create();
    $product->recordRegulatoryReview($this->staff, 'FDA/GH/2026/0142');

    $product->fresh()->update(['description' => 'Herbal remedy, vitamin supplement and antibiotic cream.']);

    expect($product->fresh()->needsRegulatoryReview())->toBeTrue()
        ->and($product->fresh()->regulatory_reviewed_at)->toBeNull();
});

it('keeps a flagged product out of the live listing', function () {
    $clean = Product::factory()->create();
    $flagged = Product::factory()->create();
    $flagged->update(['description' => 'Contains a herbal remedy.']);

    expect(Product::query()->live()->pluck('id')->all())->toBe([$clean->id]);
});

it('lists what an administrator has to deal with', function () {
    Product::factory()->regulated()->create();
    Product::factory()->create();

    expect(Product::query()->awaitingRegulatoryReview()->count())->toBe(1);
});

it('records which keywords tripped, so the editor knows what to look at', function () {
    $product = Product::factory()->regulated()->create();

    expect($product->regulatoryFlags())->toContain('supplement')
        ->and($product->regulatoryFlags())->toContain('vitamin');
});

// ═══════════════════════════════════════════════════════════════════════════
//  Stock as a ledger
// ═══════════════════════════════════════════════════════════════════════════

it('records every stock change as a movement with a reason', function () {
    $variant = ProductVariant::factory()->create();

    $variant->restock(24, 'Delivery note 1182', $this->staff);

    expect($variant->fresh()->stock_on_hand)->toBe(24)
        ->and(InventoryMovement::count())->toBe(1)
        ->and(InventoryMovement::first()->reason)->toBe(InventoryMovement::REASON_RESTOCK)
        ->and(InventoryMovement::first()->balance_after)->toBe(24);
});

it('rebuilds the cached figure from the ledger', function () {
    // The ledger is the truth; the column is a cache of it.
    $variant = ProductVariant::factory()->create();
    $variant->restock(24);
    $variant->adjust(-2, 'Two broken in transit.');

    // Corrupt the cache the way a bad migration or a manual edit would.
    ProductVariant::whereKey($variant->id)->update(['stock_on_hand' => 999]);

    expect($variant->fresh()->stockReconciles())->toBeFalse()
        ->and($variant->fresh()->recalculateStock())->toBe(22);
});

it('finds a variant whose cache has drifted from its ledger', function () {
    $variant = ProductVariant::factory()->create();
    $variant->restock(10);

    ProductVariant::whereKey($variant->id)->update(['stock_on_hand' => 7]);

    expect(ProductVariant::query()->outOfBalance()->pluck('id')->all())->toBe([$variant->id]);
});

it('refuses an adjustment with no explanation', function () {
    // An unexplained adjustment is indistinguishable from a loss.
    ProductVariant::factory()->create()->adjust(-3, '');
})->throws(RuntimeException::class, 'needs a note');

it('never edits or deletes a movement', function () {
    $variant = ProductVariant::factory()->create();
    $variant->restock(5);

    InventoryMovement::first()->update(['quantity' => 500]);
})->throws(RuntimeException::class, 'append-only');

it('refuses to delete a movement', function () {
    ProductVariant::factory()->create()->restock(5);

    InventoryMovement::first()->delete();
})->throws(RuntimeException::class, 'never deleted');

// ═══════════════════════════════════════════════════════════════════════════
//  Holds — so two customers cannot buy the last mug
// ═══════════════════════════════════════════════════════════════════════════

it('holds stock for an order awaiting payment', function () {
    $variant = ProductVariant::factory()->create();
    $variant->restock(3);

    $variant->hold(2, 'SCGHF-O-ABC');

    expect($variant->fresh()->stock_on_hand)->toBe(3)
        // On hand is unchanged — the goods have not moved — but only one is
        // sellable.
        ->and($variant->fresh()->sellableQuantity())->toBe(1);
});

it('will not hold more than remains sellable', function () {
    $variant = ProductVariant::factory()->create();
    $variant->restock(2);
    $variant->hold(2, 'SCGHF-O-ABC');

    $variant->fresh()->hold(1, 'SCGHF-O-DEF');
})->throws(RuntimeException::class, 'remain available');

it('gives held stock back when a payment fails', function () {
    $variant = ProductVariant::factory()->create();
    $variant->restock(3);
    $variant->hold(2, 'SCGHF-O-ABC');

    $variant->fresh()->releaseHold(2, 'SCGHF-O-ABC');

    expect($variant->fresh()->sellableQuantity())->toBe(3);
});

it('cannot be driven negative by a repeated release', function () {
    // The abandonment sweep can run twice; GREATEST(0, …) is what stops that
    // manufacturing stock out of nothing.
    $variant = ProductVariant::factory()->create();
    $variant->restock(3);
    $variant->hold(1, 'SCGHF-O-ABC');

    $variant->fresh()->releaseHold(1, 'SCGHF-O-ABC');
    $variant->fresh()->releaseHold(1, 'SCGHF-O-ABC');

    expect($variant->fresh()->stock_held)->toBe(0);
});

it('converts a hold into a sale in one step', function () {
    $variant = ProductVariant::factory()->create();
    $variant->restock(3);
    $variant->hold(2, 'SCGHF-O-ABC');

    $variant->fresh()->commitSale(2, 'SCGHF-O-ABC');

    // No instant where the goods are both held and sold.
    expect($variant->fresh()->stock_on_hand)->toBe(1)
        ->and($variant->fresh()->stock_held)->toBe(0)
        ->and($variant->fresh()->sellableQuantity())->toBe(1);
});

it('traces every movement for an order back to its reference', function () {
    $variant = ProductVariant::factory()->create();
    $variant->restock(5);
    $variant->hold(1, 'SCGHF-O-ABC');
    $variant->fresh()->commitSale(1, 'SCGHF-O-ABC');

    expect(InventoryMovement::query()->forReference('SCGHF-O-ABC')->count())->toBe(2);
});

// ═══════════════════════════════════════════════════════════════════════════
//  Sellability
// ═══════════════════════════════════════════════════════════════════════════

it('treats an untracked variant as always sellable', function () {
    // A digital download does not run out.
    $variant = ProductVariant::factory()->untracked()->create();

    expect($variant->isSellable(9_999))->toBeTrue();
});

it('does not sell an inactive variant even when it is in stock', function () {
    $variant = ProductVariant::factory()->create(['is_active' => false]);
    $variant->restock(10);

    expect($variant->fresh()->isSellable())->toBeFalse();
});

it('allows a backordered variant to sell past zero', function () {
    $variant = ProductVariant::factory()->create(['allow_backorder' => true]);

    expect($variant->isSellable(5))->toBeTrue();
});

it('lists only sellable variants', function () {
    $inStock = ProductVariant::factory()->create();
    $inStock->restock(5);
    ProductVariant::factory()->outOfStock()->create();

    expect(ProductVariant::query()->sellable()->pluck('id')->all())->toBe([$inStock->id]);
});

it('reports a from-price across variants', function () {
    $product = Product::factory()->create();
    ProductVariant::factory()->create(['product_id' => $product->id, 'price' => 4_500]);
    ProductVariant::factory()->create(['product_id' => $product->id, 'price' => 2_500]);

    expect($product->fresh()->fromPrice())->toEqualPesewas(2_500);
});

it('refuses a negative price', function () {
    ProductVariant::factory()->create(['price' => -100]);
})->throws(RuntimeException::class, 'cannot be negative');

it('stores prices as integer pesewas', function () {
    $variant = ProductVariant::factory()->create(['price' => 4_500]);

    expect($variant->fresh()->price)->toEqualPesewas(4_500)
        ->and($variant->fresh()->price->format())->toBe('GH₵ 45.00');
});
