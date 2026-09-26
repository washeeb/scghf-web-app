<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A picture of a product, optionally tied to one variant.
 *
 * `alt_text` is its own column rather than borrowed from the media library's
 * name, because WCAG 2.2 AA needs alternative text that describes the image IN
 * THIS CONTEXT — "navy polo shirt, front" rather than the file name somebody
 * happened to upload.
 */
class ProductImage extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id', 'product_variant_id', 'media_id', 'alt_text', 'sort_order',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['sort_order' => 0];

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /** @return BelongsTo<Media, $this> */
    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'media_id');
    }

    /**
     * Alternative text, falling back to something describable.
     *
     * Never returns an empty string for a content image: a screen reader
     * announcing nothing where a product photograph is tells the user less than
     * the product's own name would.
     */
    public function alt(): string
    {
        return filled($this->alt_text)
            ? (string) $this->alt_text
            : trim(($this->product?->name ?? 'Product').' '.($this->variant?->name ?? ''));
    }
}
