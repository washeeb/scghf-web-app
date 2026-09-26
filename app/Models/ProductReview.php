<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use InvalidArgumentException;
use RuntimeException;

/**
 * A customer's review of a shop product.
 *
 * `reviews.moderate` has been a granted permission since Module 1, over a table
 * that did not exist — a permission that grants nothing is a permission
 * somebody has audited and believed.
 *
 * ── Moderated BEFORE publication, not after ─────────────────────────────────
 *
 * Post-moderation means an offensive review sits on the foundation's website
 * until somebody notices. For an organisation whose standing is most of what it
 * has, that is the wrong way round — and a shop attached to a charity is an
 * obvious target for exactly that.
 *
 * ── Verified means it can be traced to an order ─────────────────────────────
 *
 * Not a badge somebody sets. `order_id` present or absent is the whole
 * definition, and "did this person actually buy it" is the only cheap defence
 * against review spam that works.
 */
class ProductReview extends Model
{
    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_SPAM = 'spam';

    protected $fillable = [
        'product_id', 'user_id', 'order_id',
        'author_name', 'author_email', 'rating', 'title', 'body', 'ip_address',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => self::STATUS_PENDING,
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'moderated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $review): void {
            if ($review->rating < 1 || $review->rating > 5) {
                throw new InvalidArgumentException('A rating must be between 1 and 5.');
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
        return 'ulid';
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Whether this review can be traced to a purchase.
     *
     * Derived, never stored. A stored flag is a flag somebody can set.
     */
    public function isVerifiedPurchase(): bool
    {
        return $this->order_id !== null;
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function approve(User $moderator): void
    {
        if (! $moderator->can('reviews.moderate')) {
            throw new RuntimeException(
                'Publishing a review needs the `reviews.moderate` permission.'
            );
        }

        $this->forceFill([
            'status' => self::STATUS_APPROVED,
            'moderated_by' => $moderator->getKey(),
            'moderated_at' => now(),
        ])->save();
    }

    public function reject(User $moderator, string $reason): void
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException(
                'Rejecting a review needs a reason. A customer who asks why theirs was not '
                .'published deserves an answer.'
            );
        }

        $this->forceFill([
            'status' => self::STATUS_REJECTED,
            'moderated_by' => $moderator->getKey(),
            'moderated_at' => now(),
            'rejection_reason' => $reason,
        ])->save();
    }

    /**
     * The average rating shown on a product page.
     *
     * Approved reviews only. Averaging in the unmoderated queue would let
     * anybody move a product's rating by submitting, whether or not their words
     * were ever published — which is the whole attack, minus the offensive text.
     */
    public static function averageFor(Product $product): ?float
    {
        $average = static::query()
            ->where('product_id', $product->getKey())
            ->where('status', self::STATUS_APPROVED)
            ->avg('rating');

        return $average === null ? null : round((float) $average, 1);
    }

    #[Scope]
    protected function published(Builder $query): void
    {
        $query->where('status', self::STATUS_APPROVED)->latest();
    }

    #[Scope]
    protected function awaitingModeration(Builder $query): void
    {
        $query->where('status', self::STATUS_PENDING)->oldest();
    }
}
