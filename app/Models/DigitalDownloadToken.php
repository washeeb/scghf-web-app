<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Access to a digital product somebody bought.
 *
 * A long random token with an expiry and a download limit, rather than a public
 * URL. A file on a guessable path is a file everyone has, and a devotional the
 * foundation sells to fund its work should not be the first result for its own
 * title.
 *
 * The limit is generous rather than mean: five downloads covers a failed
 * transfer, a second device and a lost file, which is the honest set of reasons
 * a paying customer needs the link again.
 */
class DigitalDownloadToken extends Model
{
    use HasFactory;

    public const DEFAULT_LIFETIME_DAYS = 30;

    protected $fillable = [
        'order_id', 'order_item_id', 'media_id', 'token',
        'max_downloads', 'expires_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'max_downloads' => 5,
        'download_count' => 0,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'last_downloaded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $token): void {
            // 64 characters of randomness. Long enough that guessing is not a
            // strategy, short enough to survive being emailed.
            $token->token ??= Str::random(64);
            $token->expires_at ??= now()->addDays(self::DEFAULT_LIFETIME_DAYS);
        });
    }

    public function getRouteKeyName(): string
    {
        return 'token';
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<OrderItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }

    /** @return BelongsTo<Media, $this> */
    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'media_id');
    }

    /**
     * Why this link will not work, or null if it will.
     *
     * A reason rather than a boolean: "this link has expired, contact us for a
     * new one" is actionable, and "access denied" is not — and the person
     * reading it has already paid.
     */
    public function rejectionReason(): ?string
    {
        if (! $this->order?->status->isPaid()) {
            return 'This order has not been paid for.';
        }

        if ($this->expires_at->isPast()) {
            return 'This download link expired on '.$this->expires_at->format('j F Y')
                .'. Contact us and we will send a fresh one.';
        }

        if ($this->download_count >= $this->max_downloads) {
            return 'This link has been used its maximum number of times. '
                .'Contact us and we will send a fresh one.';
        }

        return null;
    }

    public function isUsable(): bool
    {
        return $this->rejectionReason() === null;
    }

    /**
     * Count a download.
     *
     * Incremented atomically: two clicks a second apart on a slow connection
     * are one customer, but a read-modify-write would count them as one use,
     * and the limit would quietly be worth more than it says.
     */
    public function recordDownload(?string $ip = null): void
    {
        static::whereKey($this->getKey())->update([
            'download_count' => DB::raw('download_count + 1'),
            'last_downloaded_at' => now(),
            'last_ip' => $ip,
            'updated_at' => now(),
        ]);

        $this->refresh();
    }

    public function remainingDownloads(): int
    {
        return max(0, $this->max_downloads - $this->download_count);
    }

    /** Issue a fresh link for a customer whose one expired. */
    public function reissue(int $days = self::DEFAULT_LIFETIME_DAYS): self
    {
        return static::create([
            'order_id' => $this->order_id,
            'order_item_id' => $this->order_item_id,
            'media_id' => $this->media_id,
            'max_downloads' => $this->max_downloads,
            'expires_at' => now()->addDays($days),
        ]);
    }

    #[Scope]
    protected function usable(Builder $query): void
    {
        $query->where('expires_at', '>', now())
            ->whereColumn('download_count', '<', 'max_downloads');
    }
}
