<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToDivision;
use App\Models\Concerns\HasSeo;
use App\Models\Concerns\RecordsAuthor;
use App\Shop\RegulatoryScreener;
use App\Support\Slug;
use App\ValueObjects\Money;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use RuntimeException;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Something the shop sells.
 *
 * **Publication is gated on a regulatory review.** Every save screens the name,
 * summary and description against the prohibited-keyword list; anything that
 * trips it is flagged and cannot be published until a review is recorded.
 *
 * The flag is not a judgement about the product. It says "somebody with the
 * authority to decide has not looked at this yet", which for medicines,
 * supplements, food and cosmetics is a decision the software has no business
 * making on its own.
 */
class Product extends Model
{
    use BelongsToDivision;
    use HasFactory;
    use HasSeo;
    use HasUlids;
    use LogsActivity;
    use RecordsAuthor;
    use SoftDeletes;

    public const TYPE_PHYSICAL = 'physical';

    public const TYPE_DIGITAL = 'digital';

    /** A ticket to an event: paying registers the buyer and issues the tickets. */
    public const TYPE_TICKET = 'ticket';

    /**
     * "Sponsor a meal": nothing is shipped and nothing is stocked. When the
     * order is paid, each line becomes a real donation to the product's
     * appeal, receipted and counted as giving — and therefore NOT counted
     * again as shop proceeds.
     */
    public const TYPE_DONATION = 'donation';

    public const TYPES = [self::TYPE_PHYSICAL, self::TYPE_DIGITAL, self::TYPE_TICKET, self::TYPE_DONATION];

    protected $fillable = [
        'product_category_id', 'cause_id', 'division_id', 'name', 'slug',
        'summary', 'description', 'specifications', 'product_type', 'featured_image_id',
        'download_media_id', 'download_limit', 'download_days', 'event_ticket_id',
        'is_featured', 'is_published', 'published_at', 'sort_order', 'created_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'product_type' => self::TYPE_PHYSICAL,
        'requires_regulatory_review' => false,
        'is_featured' => false,
        'is_published' => false,
        'sort_order' => 0,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'requires_regulatory_review' => 'boolean',
            'specifications' => 'array',
            'download_limit' => 'integer',
            'download_days' => 'integer',
            'is_featured' => 'boolean',
            'is_published' => 'boolean',
            'published_at' => 'datetime',
            'regulatory_reviewed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $product): void {
            $product->slug = Slug::for($product->slug, $product->name);

            $product->screenForRegulatedGoods();

            /*
             * The gate, on every save rather than only in publish(). Otherwise
             * `update(['is_published' => true])` — the most natural thing an
             * admin action would do — walks straight past it.
             *
             * It UNPUBLISHES rather than refusing the save. Refusing would
             * throw away the editor's work and leave the older text live, which
             * looks like the edit simply failed. Unpublishing keeps the text,
             * takes the product off the shop, and leaves a flag somebody has to
             * clear — which is the outcome that is safe AND explicable.
             *
             * An explicit `publish()` still throws, because there a human has
             * asked for something the software should refuse out loud.
             */
            if ($product->is_published && $product->needsRegulatoryReview()) {
                $product->is_published = false;
                $product->unpublishedByScreening = true;
            }
        });
    }

    /** @return array<int, string> */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    // ── Relationships ────────────────────────────────────────────────────────

    /** @return BelongsTo<ProductCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'product_category_id');
    }

    /** @return BelongsTo<Cause, $this> */
    public function cause(): BelongsTo
    {
        return $this->belongsTo(Cause::class);
    }

    /** @return HasMany<ProductVariant, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('sort_order');
    }

    /** @return HasMany<ProductImage, $this> */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order');
    }

    /** @return BelongsTo<Media, $this> */
    public function featuredImage(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'featured_image_id');
    }

    /**
     * The file a digital product delivers.
     *
     * @return BelongsTo<Media, $this>
     */
    public function downloadMedia(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'download_media_id');
    }

    /** @return BelongsTo<EventTicket, $this> */
    public function eventTicket(): BelongsTo
    {
        return $this->belongsTo(EventTicket::class);
    }

    /**
     * "You might also like", chosen by the editor rather than guessed.
     *
     * @return BelongsToMany<Product, $this>
     */
    public function related(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_related', 'product_id', 'related_product_id')
            ->withPivot('sort_order')
            ->orderByPivot('sort_order');
    }

    // ── Regulatory screening ─────────────────────────────────────────────────

    /**
     * Re-screen and set the flag.
     *
     * Runs on every save, so editing a published mug's description to mention
     * "vitamin water" re-flags it rather than sailing through because it was
     * approved once.
     *
     * A review already recorded is CLEARED when new flags appear that the
     * review did not cover — approving "notebook" does not approve the
     * "supplement" somebody added to it afterwards.
     */
    public function screenForRegulatedGoods(): void
    {
        $flags = app(RegulatoryScreener::class)->screen(
            (string) $this->name,
            (string) $this->summary,
            (string) $this->description,
        );

        $previous = $this->regulatoryFlags();

        $this->requires_regulatory_review = $flags !== [];
        $this->regulatory_flags = $flags === [] ? null : implode(',', $flags);

        if ($flags !== [] && array_diff($flags, $previous) !== []) {
            $this->regulatory_reviewed_at = null;
            $this->regulatory_reviewed_by = null;
            $this->regulatory_reference = null;
        }
    }

    /** @return array<int, string> */
    public function regulatoryFlags(): array
    {
        return blank($this->regulatory_flags)
            ? []
            : array_values(array_filter(explode(',', (string) $this->regulatory_flags)));
    }

    /** Flagged, and nobody has recorded a review of it. */
    public function needsRegulatoryReview(): bool
    {
        return $this->requires_regulatory_review && $this->regulatory_reviewed_at === null;
    }

    /**
     * Record that a review happened.
     *
     * The reference is required: "we looked at it" is not a review, and an
     * auditor asking which FDA correspondence covers this product needs an
     * answer.
     */
    public function recordRegulatoryReview(User $by, string $reference): void
    {
        if (blank($reference)) {
            throw new RuntimeException(
                'A regulatory review needs a reference — the FDA correspondence, the licence '
                .'number, or the board minute that authorised it.'
            );
        }

        $this->forceFill([
            'regulatory_reviewed_at' => now(),
            'regulatory_reviewed_by' => $by->getKey(),
            'regulatory_reference' => $reference,
        ])->save();
    }

    // ── Pricing and stock ────────────────────────────────────────────────────

    /** The cheapest active variant, for a "from GH₵ x" listing. */
    public function fromPrice(): ?Money
    {
        return $this->variants
            ->where('is_active', true)
            ->sortBy('price_minor')
            ->first()?->price;
    }

    public function isInStock(): bool
    {
        return $this->variants->contains(fn (ProductVariant $variant): bool => $variant->isSellable());
    }

    public function isDigital(): bool
    {
        return $this->product_type === self::TYPE_DIGITAL;
    }

    public function isTicket(): bool
    {
        return $this->product_type === self::TYPE_TICKET;
    }

    public function isDonation(): bool
    {
        return $this->product_type === self::TYPE_DONATION;
    }

    /** Only a physical product has to be carried somewhere. */
    public function requiresDelivery(): bool
    {
        return $this->product_type === self::TYPE_PHYSICAL;
    }

    public function isLive(): bool
    {
        return $this->is_published
            && ! $this->needsRegulatoryReview()
            && ($this->published_at === null || $this->published_at->isPast());
    }

    /**
     * Set when the last save took the product off the shop because a new
     * regulatory flag appeared. Not persisted — it is a signal for the request
     * that made the change, so the editor is told why.
     */
    public bool $unpublishedByScreening = false;

    /**
     * Publish, or refuse out loud.
     *
     * Distinct from a plain save, which quietly unpublishes a newly-flagged
     * product. Here a human has explicitly asked, so an explicit refusal —
     * naming the keywords and the regulator — is the useful answer.
     */
    public function publish(?\DateTimeInterface $at = null): void
    {
        $this->screenForRegulatedGoods();

        if ($this->needsRegulatoryReview()) {
            throw new RuntimeException(
                sprintf(
                    'Cannot publish "%s": it looks like a regulated product (%s). %s '
                    .'Record the review before publishing.',
                    $this->name,
                    implode(', ', $this->regulatoryFlags()),
                    app(RegulatoryScreener::class)->notice(),
                )
            );
        }

        $this->forceFill([
            'is_published' => true,
            'published_at' => $at ?? now(),
        ])->save();
    }

    #[Scope]
    protected function live(Builder $query): void
    {
        $query->where('is_published', true)
            ->where(fn (Builder $q) => $q->where('requires_regulatory_review', false)
                ->orWhereNotNull('regulatory_reviewed_at'))
            ->where(fn (Builder $q) => $q->whereNull('published_at')->orWhere('published_at', '<=', now()));
    }

    /** Products an administrator has to deal with before they can be listed. */
    #[Scope]
    protected function awaitingRegulatoryReview(Builder $query): void
    {
        $query->where('requires_regulatory_review', true)->whereNull('regulatory_reviewed_at');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'name', 'slug', 'is_published', 'product_category_id',
                'requires_regulatory_review', 'regulatory_reviewed_at', 'regulatory_reference',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('product');
    }
}
