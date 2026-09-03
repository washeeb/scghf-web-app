<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\Retainable;
use App\Models\Concerns\DeIdentifiable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One row per email we tried to send — including the ones that never left.
 *
 * A refusal is an outcome, not an absence. A receipt blocked by the suppression
 * list produces a row saying `suppressed`, with the reason in words, so Finance
 * can post it or hand it over. Nothing in this module is allowed to fail by
 * leaving no trace, because the failure that hurts most here is the silent one:
 * a donor who never received their receipt and a foundation that believes it
 * sent one.
 *
 * This table is also the rate limiter. `sentInLastHour()` counts rows rather
 * than reading a counter, so the throttle is measuring what actually happened
 * and cannot drift when a cron-launched worker is killed mid-batch.
 */
class EmailLog extends Model implements Retainable
{
    use DeIdentifiable;
    use HasFactory;
    use HasUlids;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_SOFT_BOUNCED = 'soft_bounced';

    public const STATUS_BOUNCED = 'bounced';

    public const STATUS_COMPLAINED = 'complained';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SUPPRESSED = 'suppressed';

    public const STATUS_DISABLED = 'disabled';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'email_template_id', 'template_key', 'category',
        'to_address', 'to_name', 'from_address', 'reply_to', 'subject',
        'body_html', 'body_text', 'body_stored',
        'related_type', 'related_id', 'user_id',
        'status', 'blocked_reason', 'error', 'attempts',
        'mailer', 'provider_message_id',
        'queued_at', 'sent_at', 'delivered_at', 'failed_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'category' => 'transactional',
        'status' => self::STATUS_QUEUED,
        'body_stored' => false,
        'attempts' => 0,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'body_stored' => 'boolean',
            'attempts' => 'integer',
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
            'opened_at' => 'datetime',
            'clicked_at' => 'datetime',
        ];
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

    /** @return BelongsTo<EmailTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(EmailTemplate::class, 'email_template_id');
    }

    /** @return MorphTo<Model, $this> */
    public function related(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ── Outcomes ─────────────────────────────────────────────────────────────

    public function markSent(?string $providerMessageId = null, ?string $mailer = null): void
    {
        $this->forceFill([
            'status' => self::STATUS_SENT,
            'sent_at' => now(),
            'attempts' => $this->attempts + 1,
            'provider_message_id' => $providerMessageId ?? $this->provider_message_id,
            'mailer' => $mailer ?? $this->mailer,
        ])->save();
    }

    /**
     * Delivery confirmed by the provider.
     *
     * Deliberately separate from `sent`. Handing a message to a transport is
     * not delivery, and treating the two as one is how a silently rejected
     * domain goes unnoticed.
     */
    public function markDelivered(): void
    {
        $this->forceFill([
            'status' => self::STATUS_DELIVERED,
            'delivered_at' => now(),
        ])->save();
    }

    public function markFailed(string $error): void
    {
        $this->forceFill([
            'status' => self::STATUS_FAILED,
            'error' => $error,
            'failed_at' => now(),
            'attempts' => $this->attempts + 1,
        ])->save();
    }

    /**
     * Blocked before sending — suppressed, disabled, or expired.
     *
     * The reason is a sentence, not a code, because the person reading it is
     * usually a member of staff wondering why a donor says they got nothing.
     */
    public function markBlocked(string $status, string $reason): void
    {
        $this->forceFill([
            'status' => $status,
            'blocked_reason' => $reason,
        ])->save();
    }

    public function markBounced(bool $hard, ?string $detail = null): void
    {
        $this->forceFill([
            'status' => $hard ? self::STATUS_BOUNCED : self::STATUS_SOFT_BOUNCED,
            'error' => $detail,
            'failed_at' => now(),
        ])->save();

        if ($hard) {
            Suppression::record(
                Suppression::CHANNEL_EMAIL,
                $this->to_address,
                Suppression::REASON_HARD_BOUNCE,
                $detail,
                'webhook',
            );
        } else {
            Suppression::recordSoftBounce(Suppression::CHANNEL_EMAIL, $this->to_address, $detail);
        }
    }

    public function markComplained(?string $detail = null): void
    {
        $this->forceFill([
            'status' => self::STATUS_COMPLAINED,
            'error' => $detail,
        ])->save();

        Suppression::record(
            Suppression::CHANNEL_EMAIL,
            $this->to_address,
            Suppression::REASON_COMPLAINT,
            $detail,
            'webhook',
        );
    }

    /**
     * Whether this message reached somebody, as far as anybody can tell.
     *
     * `sent` counts, because most transports report nothing further and
     * treating "no news" as failure would flag every successful message.
     */
    public function wasDelivered(): bool
    {
        return in_array($this->status, [self::STATUS_SENT, self::STATUS_DELIVERED], true);
    }

    /** Never left, and somebody may need to do something about it. */
    public function needsAttention(): bool
    {
        return in_array($this->status, [
            self::STATUS_SUPPRESSED, self::STATUS_FAILED,
            self::STATUS_BOUNCED, self::STATUS_EXPIRED,
        ], true) && $this->category === 'transactional';
    }

    // ── Throttle ─────────────────────────────────────────────────────────────

    /**
     * How many emails actually went in the last hour.
     *
     * The rate limiter's source of truth. A COUNT rather than a counter: it is
     * atomic without a lock, it survives a worker being killed mid-batch, and
     * it cannot drift away from reality because it IS reality.
     */
    public static function sentInLastHour(): int
    {
        return static::query()->where('sent_at', '>=', now()->subHour())->count();
    }

    public static function sentInLastMinute(): int
    {
        return static::query()->where('sent_at', '>=', now()->subMinute())->count();
    }

    // ── Retention ────────────────────────────────────────────────────────────

    public function retentionClass(): string
    {
        return 'communication_log';
    }

    public function retentionAnchorDate(): ?Carbon
    {
        return $this->created_at;
    }

    public function retentionScopeKey(): ?string
    {
        return 'template:'.($this->template_key ?? 'none');
    }

    /** @return array<string, string> */
    public static function privacyElements(): array
    {
        return [
            'to_address' => 'email',
            'to_name' => 'name',
            // A rendered receipt or a rendered case update carries whatever the
            // message carried. It is not a summary of personal data, it is the
            // personal data.
            'body_html' => 'narrative',
            'body_text' => 'narrative',
            'subject' => 'narrative',
            'provider_message_id' => 'device',
            'category' => 'indicator',
        ];
    }

    /** @return array<int, string> */
    public static function privacyExempt(): array
    {
        return [
            'id', 'ulid', 'created_at', 'updated_at',
            'email_template_id', 'template_key', 'from_address', 'reply_to',
            'body_stored', 'related_type', 'related_id', 'user_id',
            'status', 'blocked_reason', 'error', 'attempts', 'mailer',
            'queued_at', 'sent_at', 'delivered_at', 'failed_at',
            'opened_at', 'clicked_at',
        ];
    }

    #[Scope]
    protected function retentionCandidates(Builder $query): void
    {
        $query->whereNotNull('created_at');
    }

    #[Scope]
    protected function failures(Builder $query): void
    {
        $query->whereIn('status', [
            self::STATUS_FAILED, self::STATUS_BOUNCED,
            self::STATUS_COMPLAINED, self::STATUS_SUPPRESSED,
        ]);
    }

    #[Scope]
    protected function forAddress(Builder $query, string $address): void
    {
        $query->where('to_address', mb_strtolower(trim($address)));
    }
}
