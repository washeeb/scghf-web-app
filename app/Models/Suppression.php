<?php

declare(strict_types=1);

namespace App\Models;

use App\Communications\PhoneNumber;
use App\Models\Concerns\RecordsAuthor;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * An address we will not send to, and why.
 *
 * Blueprint risk DEL-4, and the table the whole channel depends on. Bounces and
 * complaints that are not suppressed degrade the sending domain's reputation
 * until mail stops arriving at all — and the first thing to stop arriving is
 * not the newsletter, it is the donation receipt.
 *
 * ── Scope is the whole design ───────────────────────────────────────────────
 *
 * "Stop emailing me" and "this mailbox does not exist" are different facts.
 *
 *   all        nothing goes, including receipts
 *   marketing  appeals and newsletters stop; receipts continue
 *
 * An unsubscribe is `marketing`. Somebody who no longer wants appeals has not
 * asked to stop receiving the record of a gift they just made — withholding
 * that would leave them with no evidence of a donation and the Foundation with
 * no evidence of having acknowledged one.
 *
 * ── Suppression only ever strengthens ───────────────────────────────────────
 *
 * An address suppressed for marketing that later hard-bounces is upgraded to
 * `all`. Nothing automatic ever moves it the other way. Coming off this list is
 * a decision by a named person with a recorded reason, because the cost of a
 * wrong release is measured in domain reputation and the cost of a wrong
 * suppression is one phone call.
 *
 * ── Retention ───────────────────────────────────────────────────────────────
 *
 * These rows hold personal data and are deliberately NOT swept. The list exists
 * precisely to prevent processing: deleting somebody's suppression because it
 * got old is how they start receiving mail again after asking not to. That is
 * the same reasoning Act 843 applies to an objection — honouring it requires
 * remembering it.
 */
class Suppression extends Model
{
    use HasFactory;
    use HasUlids;
    use RecordsAuthor;

    public const CHANNEL_EMAIL = 'email';

    public const CHANNEL_SMS = 'sms';

    public const SCOPE_ALL = 'all';

    public const SCOPE_MARKETING = 'marketing';

    public const REASON_HARD_BOUNCE = 'hard_bounce';

    public const REASON_COMPLAINT = 'complaint';

    public const REASON_UNSUBSCRIBE = 'unsubscribe';

    public const REASON_INVALID = 'invalid';

    public const REASON_ERASURE = 'erasure_request';

    public const REASON_MANUAL = 'manual';

    public const REASON_SOFT_BOUNCE = 'soft_bounce_repeated';

    protected $fillable = [
        'channel', 'address', 'scope', 'reason', 'detail', 'source',
        'suppressed_at', 'expires_at', 'occurrences', 'last_seen_at', 'created_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'scope' => self::SCOPE_ALL,
        'source' => 'system',
        'occurrences' => 1,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'suppressed_at' => 'datetime',
            'expires_at' => 'datetime',
            'released_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'occurrences' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $suppression): void {
            $suppression->address = self::normaliseAddress($suppression->channel, $suppression->address);
            $suppression->suppressed_at ??= now();
            $suppression->last_seen_at ??= $suppression->suppressed_at;
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

    /** @return BelongsTo<User, $this> */
    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }

    // ── Recording ────────────────────────────────────────────────────────────

    /**
     * Suppress an address, or strengthen an existing suppression.
     *
     * Idempotent by design: a provider that reports the same bounce three times
     * produces one row with `occurrences` at three, not three rows. Webhooks
     * retry, and a suppression list that grows a row per retry is a suppression
     * list nobody can read.
     *
     * The scope comes from the reason, via config, so the policy lives in one
     * readable place rather than at every call site.
     */
    public static function record(
        string $channel,
        string $address,
        string $reason,
        ?string $detail = null,
        string $source = 'system',
        ?User $actor = null,
        ?string $scope = null,
    ): self {
        $normalised = self::normaliseAddress($channel, $address);
        $scope ??= self::scopeForReason($reason);

        return DB::transaction(function () use (
            $channel, $normalised, $reason, $detail, $source, $actor, $scope
        ): self {
            $existing = static::query()
                ->where('channel', $channel)
                ->where('address', $normalised)
                ->lockForUpdate()
                ->first();

            if ($existing === null) {
                return static::create([
                    'channel' => $channel,
                    'address' => $normalised,
                    'scope' => $scope,
                    'reason' => $reason,
                    'detail' => $detail,
                    'source' => $source,
                    'created_by' => $actor?->getKey(),
                ]);
            }

            /*
             * Strengthen only. `all` is stronger than `marketing`, so a row
             * already at `all` keeps it whatever arrives next — a newsletter
             * unsubscribe must never re-enable mail to a dead mailbox.
             */
            $strengthened = $existing->scope === self::SCOPE_ALL || $scope === self::SCOPE_ALL
                ? self::SCOPE_ALL
                : self::SCOPE_MARKETING;

            $existing->forceFill([
                'scope' => $strengthened,
                // The reason is only overwritten when the scope actually
                // widened, so a hard bounce does not lose its explanation to a
                // later unsubscribe.
                'reason' => $strengthened !== $existing->scope ? $reason : $existing->reason,
                'detail' => $detail ?? $existing->detail,
                'occurrences' => $existing->occurrences + 1,
                'last_seen_at' => now(),
                // A repeat event on a released address re-suppresses it. The
                // release was a judgement that turned out to be wrong.
                'released_at' => null,
                'released_by' => null,
                'release_reason' => null,
            ])->save();

            return $existing;
        });
    }

    /**
     * Record a soft bounce, suppressing only once they stop looking temporary.
     *
     * A full mailbox is not a dead address. A mailbox that has been full for
     * five consecutive sends is, in practice, gone — and continuing to hammer
     * it counts against the sending domain exactly like a hard bounce.
     */
    public static function recordSoftBounce(string $channel, string $address, ?string $detail = null): ?self
    {
        $normalised = self::normaliseAddress($channel, $address);
        $threshold = (int) config('communications.suppression.soft_bounce_threshold', 5);

        $recent = EmailLog::query()
            ->where('to_address', $normalised)
            ->where('status', EmailLog::STATUS_SOFT_BOUNCED)
            ->where('created_at', '>=', now()->subDays(30))
            ->count();

        if ($recent < $threshold) {
            return null;
        }

        return self::record(
            $channel,
            $normalised,
            self::REASON_SOFT_BOUNCE,
            $detail ?? "{$recent} soft bounces in the last 30 days.",
            'system',
        );
    }

    // ── Checking ─────────────────────────────────────────────────────────────

    /**
     * The active suppression blocking a message of this category, or null.
     *
     * A category rather than a boolean because the answer differs: a
     * transactional receipt is blocked by `all` and not by `marketing`.
     */
    public static function blocking(string $channel, string $address, string $category): ?self
    {
        $normalised = self::tryNormaliseAddress($channel, $address);

        if ($normalised === null) {
            return null;
        }

        /** @var array<int, string> $blockedBy */
        $blockedBy = config("communications.categories.{$category}.blocked_by_scopes", [self::SCOPE_ALL]);

        return static::query()
            ->active()
            ->where('channel', $channel)
            ->where('address', $normalised)
            ->whereIn('scope', $blockedBy)
            ->first();
    }

    public static function blocks(string $channel, string $address, string $category): bool
    {
        return self::blocking($channel, $address, $category) !== null;
    }

    public function isActive(): bool
    {
        return $this->released_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function reasonLabel(): string
    {
        return (string) config(
            "communications.suppression.reasons.{$this->reason}.label",
            $this->reason,
        );
    }

    /** The sentence a log entry or an admin screen shows. */
    public function explanation(): string
    {
        return sprintf(
            '%s (%s), recorded %s.',
            $this->reasonLabel(),
            $this->scope === self::SCOPE_ALL ? 'all messages' : 'marketing only',
            $this->suppressed_at?->format('j F Y') ?? 'unknown',
        );
    }

    // ── Release ──────────────────────────────────────────────────────────────

    /**
     * Take an address off the list.
     *
     * Requires a person and a reason, both recorded. There is deliberately no
     * automatic path off this list: releasing a hard bounce because it looks
     * old sends mail to a mailbox that does not exist, which is precisely the
     * behaviour that gets a domain blocklisted.
     */
    public function release(User $actor, string $reason): void
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException(
                'Releasing a suppression needs a reason. This is a decision somebody is '
                .'accountable for, not a tidy-up.'
            );
        }

        if ($this->reason === self::REASON_ERASURE) {
            throw new RuntimeException(
                'This address is suppressed because the data subject objected to being '
                .'contacted under Act 843. It cannot be released — honouring the objection '
                .'is what the row is for.'
            );
        }

        $this->forceFill([
            'released_at' => now(),
            'released_by' => $actor->getKey(),
            'release_reason' => $reason,
        ])->save();
    }

    // ── Address normalisation ────────────────────────────────────────────────

    /**
     * Normalise an address for storage and lookup.
     *
     * The entire value of this table depends on it. `Ama@Example.com` and
     * `ama@example.com` are one mailbox; `024 123 4567` and `+233241234567` are
     * one phone. Stored unnormalised they are separate rows, and every one of
     * them is a chance to contact somebody who asked not to be.
     */
    public static function normaliseAddress(string $channel, string $address): string
    {
        return match ($channel) {
            self::CHANNEL_EMAIL => mb_strtolower(trim($address)),
            self::CHANNEL_SMS => PhoneNumber::normalise($address),
            default => throw new InvalidArgumentException("Unknown channel [{$channel}]."),
        };
    }

    public static function tryNormaliseAddress(string $channel, string $address): ?string
    {
        try {
            return self::normaliseAddress($channel, $address);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public static function scopeForReason(string $reason): string
    {
        return (string) config(
            "communications.suppression.reasons.{$reason}.scope",
            self::SCOPE_ALL,
        );
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->whereNull('released_at')
            ->where(function (Builder $q): void {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }

    #[Scope]
    protected function forChannel(Builder $query, string $channel): void
    {
        $query->where('channel', $channel);
    }
}
