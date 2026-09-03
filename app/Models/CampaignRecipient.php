<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person on one campaign's build list.
 *
 * A build list, NOT a permission. Whether the message may actually go to them
 * is read from the suppression list at the moment of sending — which on this
 * host is hours after the list was built, and somebody who unsubscribes in that
 * window has unsubscribed.
 *
 * No `ulid`: never exposed, never in a URL, and two thousand rows per campaign
 * is not the place to spend twenty-six bytes and a unique index on an
 * identifier nobody will ever quote.
 */
class CampaignRecipient extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_FAILED = 'failed';

    public const SKIP_SUPPRESSED = 'suppressed';

    public const SKIP_UNCONFIRMED = 'unconfirmed';

    public const SKIP_UNSUBSCRIBED = 'unsubscribed';

    public const SKIP_INVALID = 'invalid';

    protected $fillable = [
        'newsletter_campaign_id', 'subscriber_id', 'email', 'name',
        'status', 'skip_reason', 'email_log_id', 'claimed_at', 'sent_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => self::STATUS_PENDING,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['claimed_at' => 'datetime', 'sent_at' => 'datetime'];
    }

    /** @return BelongsTo<NewsletterCampaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(NewsletterCampaign::class, 'newsletter_campaign_id');
    }

    /** @return BelongsTo<Subscriber, $this> */
    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class);
    }

    /** @return BelongsTo<EmailLog, $this> */
    public function emailLog(): BelongsTo
    {
        return $this->belongsTo(EmailLog::class, 'email_log_id');
    }

    public function markSent(EmailLog $log): void
    {
        $this->forceFill([
            'status' => self::STATUS_SENT,
            'email_log_id' => $log->getKey(),
            'sent_at' => now(),
        ])->save();
    }

    /**
     * Not sent, and why.
     *
     * Skipping is recorded rather than deleted, so "why did I not get the
     * newsletter?" has an answer that does not require guessing.
     */
    public function skip(string $reason, ?EmailLog $log = null): void
    {
        $this->forceFill([
            'status' => self::STATUS_SKIPPED,
            'skip_reason' => $reason,
            'email_log_id' => $log?->getKey(),
        ])->save();
    }

    public function fail(?EmailLog $log = null): void
    {
        $this->forceFill([
            'status' => self::STATUS_FAILED,
            'email_log_id' => $log?->getKey(),
        ])->save();
    }

    #[Scope]
    protected function pending(Builder $query): void
    {
        $query->where('status', self::STATUS_PENDING);
    }
}
