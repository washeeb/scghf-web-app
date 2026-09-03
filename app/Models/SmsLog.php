<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\Retainable;
use App\Models\Concerns\DeIdentifiable;
use App\ValueObjects\Money;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One row per SMS, sent or not, costed either way.
 *
 * With `SMS_DRIVER=log` this table fills exactly as if messages were going out
 * — segmented, encoded, costed, attributed to a network — while nothing is
 * sent. That is deliberate: the Foundation can see what a month of SMS would
 * have cost, and which templates cost the most, before signing with a provider.
 *
 * ── `sent` is not `delivered`, and here it really matters ───────────────────
 *
 * A sender ID that is not registered with the Ghanaian networks is accepted by
 * the provider and dropped by the network, with no error returned anywhere. So
 * `provider_status` is recorded separately from our own status, and a message
 * we handed over and heard nothing more about stays `sent`. Marking it
 * `delivered` on optimism is precisely how a silently blocked sender ID goes
 * unnoticed for a month.
 */
class SmsLog extends Model implements Retainable
{
    use DeIdentifiable;
    use HasFactory;
    use HasUlids;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENT = 'sent';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SUPPRESSED = 'suppressed';

    public const STATUS_DISABLED = 'disabled';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_UNDELIVERED = 'undelivered';

    protected $fillable = [
        'sms_template_id', 'template_key', 'category',
        'to_number', 'network', 'sender_id', 'body',
        'encoding', 'character_count', 'segments',
        'estimated_cost_minor', 'currency',
        'driver', 'provider_message_id', 'provider_status',
        'status', 'blocked_reason', 'error', 'attempts',
        'related_type', 'related_id', 'user_id',
        'queued_at', 'sent_at', 'delivered_at', 'failed_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'category' => 'transactional',
        'status' => self::STATUS_QUEUED,
        'network' => 'unknown',
        'encoding' => 'gsm7',
        'segments' => 1,
        'estimated_cost_minor' => 0,
        'currency' => 'GHS',
        'attempts' => 0,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'character_count' => 'integer',
            'segments' => 'integer',
            'estimated_cost_minor' => 'integer',
            'attempts' => 'integer',
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
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

    /** @return BelongsTo<SmsTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(SmsTemplate::class, 'sms_template_id');
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

    /** The estimated cost as money, so it formats like every other amount. */
    public function estimatedCost(): Money
    {
        return Money::ofMinor((int) $this->estimated_cost_minor, (string) $this->currency);
    }

    // ── Outcomes ─────────────────────────────────────────────────────────────

    public function markSent(?string $providerMessageId = null, ?string $providerStatus = null): void
    {
        $this->forceFill([
            'status' => self::STATUS_SENT,
            'sent_at' => now(),
            'attempts' => $this->attempts + 1,
            'provider_message_id' => $providerMessageId ?? $this->provider_message_id,
            'provider_status' => $providerStatus ?? $this->provider_status,
        ])->save();
    }

    /** Only ever called from a delivery report. Never inferred. */
    public function markDelivered(?string $providerStatus = null): void
    {
        $this->forceFill([
            'status' => self::STATUS_DELIVERED,
            'delivered_at' => now(),
            'provider_status' => $providerStatus ?? $this->provider_status,
        ])->save();
    }

    public function markUndelivered(string $providerStatus, ?string $detail = null): void
    {
        $this->forceFill([
            'status' => self::STATUS_UNDELIVERED,
            'provider_status' => $providerStatus,
            'error' => $detail,
            'failed_at' => now(),
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

    public function markBlocked(string $status, string $reason): void
    {
        $this->forceFill(['status' => $status, 'blocked_reason' => $reason])->save();
    }

    // ── Cost reporting ───────────────────────────────────────────────────────

    /**
     * Estimated spend over a period, in integer pesewas.
     *
     * Counts only messages that were actually handed to a provider — a
     * suppressed message costs nothing.
     */
    public static function estimatedSpendMinor(Carbon $from, ?Carbon $to = null): int
    {
        return (int) static::query()
            ->whereIn('status', [self::STATUS_SENT, self::STATUS_DELIVERED, self::STATUS_UNDELIVERED])
            ->whereBetween('sent_at', [$from, $to ?? now()])
            ->sum('estimated_cost_minor');
    }

    public static function estimatedSpendThisMonth(): Money
    {
        return Money::ofMinor(
            self::estimatedSpendMinor(now()->startOfMonth()),
            (string) config('payments.currency', 'GHS'),
        );
    }

    /**
     * Whether the month's estimated spend has passed the configured alert
     * threshold.
     *
     * An alert, not a stop. Cutting off SMS mid-month would silence exactly the
     * messages that matter most — and the threshold is an estimate against a
     * rate that may be wrong, which is not a good enough reason to stop talking
     * to people.
     */
    public static function overMonthlyBudget(): bool
    {
        $budgetMinor = (int) config('communications.sms.monthly_budget_minor', 0);

        return $budgetMinor > 0
            && self::estimatedSpendMinor(now()->startOfMonth()) > $budgetMinor;
    }

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
            'to_number' => 'phone',
            'body' => 'narrative',
            'provider_message_id' => 'device',
            'network' => 'indicator',
            'category' => 'indicator',
        ];
    }

    /** @return array<int, string> */
    public static function privacyExempt(): array
    {
        return [
            'id', 'ulid', 'created_at', 'updated_at',
            'sms_template_id', 'template_key', 'sender_id',
            'encoding', 'character_count', 'segments',
            'estimated_cost_minor', 'currency', 'driver', 'provider_status',
            'status', 'blocked_reason', 'error', 'attempts',
            'related_type', 'related_id', 'user_id',
            'queued_at', 'sent_at', 'delivered_at', 'failed_at',
        ];
    }

    #[Scope]
    protected function retentionCandidates(Builder $query): void
    {
        $query->whereNotNull('created_at');
    }

    #[Scope]
    protected function billable(Builder $query): void
    {
        $query->whereIn('status', [
            self::STATUS_SENT, self::STATUS_DELIVERED, self::STATUS_UNDELIVERED,
        ]);
    }
}
