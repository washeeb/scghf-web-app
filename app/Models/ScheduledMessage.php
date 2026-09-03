<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The outbox: a message that is going to be sent, later.
 *
 * Not Laravel's queue. The queue is for work; this is for messages, and on
 * shared hosting the difference is the whole design. A message needs to be
 * visible to staff, cancellable, orderable by priority, and above all
 * EXPIRABLE — none of which a serialised job in `jobs` is.
 *
 * ── Why expiry exists ───────────────────────────────────────────────────────
 *
 * cPanel caps outbound mail at a couple of hundred an hour, so a backlog here
 * is measured in days rather than seconds. A queue that eventually catches up
 * and delivers "Reminder: the event is tomorrow" three days after the event is
 * worse than one that delivers nothing and records why. Receipts have no
 * expiry — a receipt is worth having late.
 *
 * ── Why claiming exists ─────────────────────────────────────────────────────
 *
 * cron starts a worker every minute and the previous one, running with
 * `--max-time=55`, may still be finishing. Two processes can therefore see the
 * same pending row. A claim taken under `lockForUpdate` — released after a TTL
 * if the worker died — is what stops a donor receiving two receipts.
 */
class ScheduledMessage extends Model
{
    use HasFactory;
    use HasUlids;

    public const CHANNEL_EMAIL = 'email';

    public const CHANNEL_SMS = 'sms';

    public const STATUS_PENDING = 'pending';

    public const STATUS_CLAIMED = 'claimed';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_SUPPRESSED = 'suppressed';

    protected $fillable = [
        'channel', 'template_key', 'category',
        'to_address', 'to_name', 'payload',
        'related_type', 'related_id', 'user_id',
        'send_after', 'expires_at', 'priority',
        'idempotency_key', 'created_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'category' => 'transactional',
        'status' => self::STATUS_PENDING,
        'priority' => 5,
        'attempts' => 0,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'send_after' => 'datetime',
            'expires_at' => 'datetime',
            'claimed_at' => 'datetime',
            'sent_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'priority' => 'integer',
            'attempts' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $message): void {
            $message->send_after ??= now();
            $message->priority = $message->priority
                ?? (int) config("communications.categories.{$message->category}.priority", 5);

            /*
             * Default shelf life by category. Applied at creation rather than
             * read at send time so that changing the policy does not silently
             * expire messages already sitting in the outbox.
             */
            if ($message->expires_at === null) {
                $hours = config("communications.scheduling.default_expiry_hours.{$message->category}");

                if ($hours !== null) {
                    $message->expires_at = $message->send_after->copy()->addHours((int) $hours);
                }
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

    /** @return MorphTo<Model, $this> */
    public function related(): MorphTo
    {
        return $this->morphTo();
    }

    // ── Draining ─────────────────────────────────────────────────────────────

    /**
     * Claim a batch of due messages for this worker.
     *
     * The claim is taken inside a transaction under `lockForUpdate`, so two
     * overlapping cron workers cannot both take the same row. Expired messages
     * are swept in the same pass rather than skipped, because a row that can
     * never be sent should not be re-examined every minute for the rest of its
     * life.
     *
     * @return Collection<int, self>
     */
    public static function claimBatch(string $workerId, ?int $limit = null, ?string $channel = null): Collection
    {
        $limit ??= (int) config('communications.scheduling.batch_size', 25);
        $ttl = (int) config('communications.scheduling.claim_ttl_minutes', 5);

        return DB::transaction(function () use ($workerId, $limit, $channel, $ttl): Collection {
            $due = static::query()
                ->when($channel !== null, fn (Builder $q) => $q->where('channel', $channel))
                ->where('send_after', '<=', now())
                ->where(function (Builder $q) use ($ttl): void {
                    $q->where('status', self::STATUS_PENDING)
                        // A claim whose worker died. The TTL is deliberately
                        // generous against a 55-second worker: reclaiming too
                        // eagerly sends the message twice, which is the worse
                        // of the two failures.
                        ->orWhere(function (Builder $stale) use ($ttl): void {
                            $stale->where('status', self::STATUS_CLAIMED)
                                ->where('claimed_at', '<', now()->subMinutes($ttl));
                        });
                })
                // Priority first so a receipt does not wait behind four hundred
                // newsletter sends, then oldest first within a priority.
                ->orderBy('priority')
                ->orderBy('send_after')
                ->limit($limit)
                ->lockForUpdate()
                ->get();

            $claimed = $due->reject(function (self $message): bool {
                if ($message->hasExpired()) {
                    $message->expire();

                    return true;
                }

                return false;
            });

            if ($claimed->isNotEmpty()) {
                static::query()
                    ->whereIn('id', $claimed->modelKeys())
                    ->update([
                        'status' => self::STATUS_CLAIMED,
                        'claimed_at' => now(),
                        'claimed_by' => $workerId,
                        'updated_at' => now(),
                    ]);
            }

            return $claimed->values();
        });
    }

    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function expire(): void
    {
        $this->forceFill([
            'status' => self::STATUS_EXPIRED,
            'last_error' => 'Not sent: this message passed its shelf life at '
                .$this->expires_at?->format('j M Y H:i').' while still queued.',
        ])->save();
    }

    public function markSent(): void
    {
        $this->forceFill([
            'status' => self::STATUS_SENT,
            'sent_at' => now(),
            'attempts' => $this->attempts + 1,
            'claimed_by' => null,
            'claimed_at' => null,
        ])->save();
    }

    public function markSuppressed(string $reason): void
    {
        $this->forceFill([
            'status' => self::STATUS_SUPPRESSED,
            'last_error' => $reason,
            'claimed_by' => null,
            'claimed_at' => null,
        ])->save();
    }

    /**
     * Record a failure, returning the message to the queue unless it is out of
     * attempts.
     *
     * A message that has failed its last attempt is left `failed` rather than
     * deleted. Somebody has to be able to see that a receipt never went.
     */
    public function markAttemptFailed(string $error): void
    {
        $attempts = $this->attempts + 1;
        $max = (int) config('communications.scheduling.max_attempts', 3);

        $this->forceFill([
            'status' => $attempts >= $max ? self::STATUS_FAILED : self::STATUS_PENDING,
            'attempts' => $attempts,
            'last_error' => $error,
            'claimed_by' => null,
            'claimed_at' => null,
            // Back off. A transport failing once usually means it will fail
            // again in the next second and not in ten minutes.
            'send_after' => now()->addMinutes(10 * $attempts),
        ])->save();
    }

    public function cancel(string $reason): void
    {
        if (in_array($this->status, [self::STATUS_SENT], true)) {
            return;
        }

        $this->forceFill([
            'status' => self::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancel_reason' => $reason,
        ])->save();
    }

    /** How many are waiting, for the admin dashboard and for the health check. */
    public static function pendingCount(?string $channel = null): int
    {
        return static::query()
            ->when($channel !== null, fn (Builder $q) => $q->where('channel', $channel))
            ->whereIn('status', [self::STATUS_PENDING, self::STATUS_CLAIMED])
            ->count();
    }

    /**
     * The oldest thing still waiting.
     *
     * On a host that sends two hundred an hour, this is the number that tells
     * staff whether the outbox is healthy — not the queue length.
     */
    public static function oldestPending(): ?Carbon
    {
        $value = static::query()
            ->whereIn('status', [self::STATUS_PENDING, self::STATUS_CLAIMED])
            ->min('send_after');

        return $value === null ? null : Carbon::parse($value);
    }

    #[Scope]
    protected function due(Builder $query): void
    {
        $query->where('status', self::STATUS_PENDING)->where('send_after', '<=', now());
    }

    #[Scope]
    protected function stuck(Builder $query): void
    {
        $query->where('status', self::STATUS_CLAIMED)
            ->where('claimed_at', '<', now()->subMinutes(
                (int) config('communications.scheduling.claim_ttl_minutes', 5)
            ));
    }
}
