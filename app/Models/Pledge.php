<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Models\Concerns\BelongsToDivision;
use App\ValueObjects\Money;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A promise to give. Deliberately not a donation.
 *
 * ── The one thing to understand about this table ────────────────────────────
 *
 * **A pledge is not income.**
 *
 * A promise of GH₵ 5,000 at harvest is a promise. If it lived in `donations`
 * with a `pledged` status, then every query that sums donations — the cause
 * thermometer, the annual report, the figure a trustee quotes to a partner —
 * would have to remember to exclude it, and the one that forgot would report
 * money the foundation does not have to somebody making decisions with it.
 *
 * So pledges live apart, and a pledge becomes income only through a real
 * donation pointing back at it. `donations` stays what it always was: things
 * that actually happened. Nothing anywhere sums pledges into a raised total.
 *
 * ── Why this matters here specifically ──────────────────────────────────────
 *
 * Harvest and thanksgiving pledging is ordinary practice in Ghanaian
 * church-linked giving. A foundation that cannot record a pledge either loses
 * track of it or, worse, books it as a gift.
 */
class Pledge extends Model
{
    use BelongsToDivision;
    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    public const STATUS_PLEDGED = 'pledged';

    public const STATUS_PARTIAL = 'partially_fulfilled';

    public const STATUS_FULFILLED = 'fulfilled';

    public const STATUS_LAPSED = 'lapsed';

    public const STATUS_CANCELLED = 'cancelled';

    public const OCCASION_HARVEST = 'harvest';

    public const OCCASION_THANKSGIVING = 'thanksgiving';

    public const OCCASION_APPEAL = 'appeal';

    public const OCCASION_EVENT = 'event';

    public const OCCASION_PERSONAL = 'personal';

    protected $fillable = [
        'donor_id', 'user_id', 'cause_id', 'division_id',
        'pledger_name', 'pledger_email', 'pledger_phone',
        'amount', 'currency', 'due_on', 'occasion', 'notes',
        'consent_to_remind', 'recorded_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => self::STATUS_PLEDGED,
        'fulfilled_minor' => 0,
        'currency' => 'GHS',
        'reminders_sent' => 0,
        'consent_to_remind' => false,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class.':amount_minor,currency',
            'fulfilled' => MoneyCast::class.':fulfilled_minor,currency',
            'due_on' => 'date',
            'consent_to_remind' => 'boolean',
            'reminders_sent' => 'integer',
            'last_reminded_at' => 'datetime',
            'fulfilled_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $pledge): void {
            $pledge->reference ??= 'SCGHF-PL-'.Str::upper(substr(Str::ulid()->toBase32(), -10));

            // Denormalised from the cause, the same way donations do it, so
            // divisional reporting does not need a join through causes.
            $pledge->division_id ??= $pledge->cause?->division_id;
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

    /** @return BelongsTo<Cause, $this> */
    public function cause(): BelongsTo
    {
        return $this->belongsTo(Cause::class);
    }

    /** @return BelongsTo<Donor, $this> */
    public function donor(): BelongsTo
    {
        return $this->belongsTo(Donor::class);
    }

    /**
     * The real gifts that fulfilled this promise.
     *
     * @return HasMany<Donation, $this>
     */
    public function donations(): HasMany
    {
        return $this->hasMany(Donation::class);
    }

    // ── Fulfilment ───────────────────────────────────────────────────────────

    /**
     * Recalculate what has actually been given against this pledge.
     *
     * Summed from COMPLETED donations, never from a figure somebody types. The
     * pledge's own amount is a promise; this is money, and the two must not be
     * capable of being confused by a typo.
     */
    public function recalculateFulfilment(): void
    {
        $paid = (int) $this->donations()
            ->where('status', 'completed')
            ->sum('amount_minor');

        $this->forceFill([
            'fulfilled_minor' => $paid,
            'status' => $this->statusFor($paid),
            'fulfilled_at' => $paid >= (int) $this->amount_minor ? ($this->fulfilled_at ?? now()) : null,
        ])->save();
    }

    private function statusFor(int $paid): string
    {
        if (in_array($this->status, [self::STATUS_CANCELLED], true)) {
            return $this->status;
        }

        return match (true) {
            $paid >= (int) $this->amount_minor => self::STATUS_FULFILLED,
            $paid > 0 => self::STATUS_PARTIAL,
            $this->hasLapsed() => self::STATUS_LAPSED,
            default => self::STATUS_PLEDGED,
        };
    }

    /** What is still promised and not yet given. */
    public function outstanding(): Money
    {
        $remaining = max(0, (int) $this->amount_minor - (int) $this->fulfilled_minor);

        return Money::ofMinor($remaining, (string) $this->currency);
    }

    public function isFulfilled(): bool
    {
        return (int) $this->fulfilled_minor >= (int) $this->amount_minor;
    }

    /**
     * Past its date and still unpaid.
     *
     * Computed from the date rather than read from the status, so a pledge does
     * not depend on a nightly job having run to become overdue — the same
     * stance the GRA approval and the safeguarding clearances take.
     */
    public function hasLapsed(): bool
    {
        return $this->due_on !== null
            && $this->due_on->isPast()
            && ! $this->isFulfilled()
            && $this->status !== self::STATUS_CANCELLED;
    }

    public function cancel(string $reason): void
    {
        $this->forceFill([
            'status' => self::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancel_reason' => $reason,
        ])->save();
    }

    // ── Reminders ────────────────────────────────────────────────────────────

    /**
     * Why this pledge should not be chased, or null if it may be.
     *
     * A reason rather than a boolean, because the wrong answer here costs a
     * supporter. Chasing somebody weekly about a promise is how a supporter
     * becomes a former supporter, and chasing somebody who never agreed to be
     * contacted is an Act 843 problem on top of that.
     */
    public function reminderRejectionReason(): ?string
    {
        if (! $this->consent_to_remind) {
            return 'This pledger did not agree to be reminded.';
        }

        if ($this->isFulfilled() || $this->status === self::STATUS_CANCELLED) {
            return 'There is nothing outstanding on this pledge.';
        }

        if (blank($this->pledger_email) && blank($this->pledger_phone)) {
            return 'There is no way to contact this pledger.';
        }

        $maximum = 3;

        if ($this->reminders_sent >= $maximum) {
            return "This pledge has already been chased {$maximum} times. A fourth reminder is a "
                .'conversation somebody should have, not another automated message.';
        }

        if ($this->last_reminded_at !== null && $this->last_reminded_at->gt(now()->subDays(14))) {
            return 'This pledge was chased less than a fortnight ago.';
        }

        return null;
    }

    public function canBeReminded(): bool
    {
        return $this->reminderRejectionReason() === null;
    }

    public function recordReminder(): void
    {
        $reason = $this->reminderRejectionReason();

        if ($reason !== null) {
            throw new RuntimeException($reason);
        }

        static::whereKey($this->getKey())->update([
            'reminders_sent' => DB::raw('reminders_sent + 1'),
            'last_reminded_at' => now(),
            'updated_at' => now(),
        ]);

        $this->refresh();
    }

    // ── Reporting ────────────────────────────────────────────────────────────

    /**
     * What has been promised and not yet received, across open pledges.
     *
     * Named to make its own limits obvious. This is NOT income, it is not
     * revenue, and it must never be added to a raised total — it is a
     * forecasting figure and nothing else.
     */
    public static function outstandingTotal(?int $causeId = null): Money
    {
        $rows = static::query()
            ->whereIn('status', [self::STATUS_PLEDGED, self::STATUS_PARTIAL])
            ->when($causeId !== null, fn (Builder $q) => $q->where('cause_id', $causeId))
            ->selectRaw('SUM(amount_minor - fulfilled_minor) as outstanding')
            ->value('outstanding');

        return Money::ofMinor((int) $rows, (string) config('payments.currency', 'GHS'));
    }

    #[Scope]
    protected function open(Builder $query): void
    {
        $query->whereIn('status', [self::STATUS_PLEDGED, self::STATUS_PARTIAL]);
    }

    #[Scope]
    protected function overdue(Builder $query): void
    {
        $query->open()->whereNotNull('due_on')->whereDate('due_on', '<', now());
    }

    /** Pledges that may lawfully and decently be chased. */
    #[Scope]
    protected function remindable(Builder $query): void
    {
        $query->open()
            ->where('consent_to_remind', true)
            ->where('reminders_sent', '<', 3)
            ->where(function (Builder $q): void {
                $q->whereNull('last_reminded_at')
                    ->orWhere('last_reminded_at', '<', now()->subDays(14));
            });
    }
}
