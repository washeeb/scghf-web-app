<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\SubscriptionStatus;
use App\ValueObjects\Money;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A standing commitment to give.
 *
 * @property SubscriptionStatus $status
 */
class Subscription extends Model
{
    use HasFactory;
    use HasUlids;
    use LogsActivity;

    public const DRIVER_MANAGED = 'managed';

    public const DRIVER_GATEWAY = 'gateway';

    public const INTERVAL_WEEKLY = 'weekly';

    public const INTERVAL_MONTHLY = 'monthly';

    public const INTERVAL_QUARTERLY = 'quarterly';

    public const INTERVAL_ANNUALLY = 'annually';

    protected $fillable = [
        'reference', 'donor_id', 'donation_plan_id', 'cause_id', 'division_id',
        'amount', 'currency', 'interval', 'driver', 'status',
        'started_on', 'next_charge_on', 'authorization_code', 'authorization_reusable',
        'channel', 'card_last4', 'paystack_subscription_code', 'paystack_customer_code',
        'paystack_email_token',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'driver' => self::DRIVER_MANAGED,
        'status' => 'active',
        'currency' => 'GHS',
        'interval' => self::INTERVAL_MONTHLY,
        'authorization_reusable' => false,
        'charge_count' => 0,
        'total_charged_minor' => 0,
        'failed_attempts' => 0,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'started_on' => 'date',
            'next_charge_on' => 'date',
            'ended_on' => 'date',
            'authorization_reusable' => 'boolean',
            'amount' => MoneyCast::class.':amount_minor,currency',
            'total_charged' => MoneyCast::class.':total_charged_minor,currency',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $subscription): void {
            $subscription->reference ??= 'SCGHF-S-'.Str::upper(substr(Str::ulid()->toBase32(), -10));
            $subscription->started_on ??= now()->toDateString();
            $subscription->next_charge_on ??= $subscription->started_on;
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

    // ── Relationships ────────────────────────────────────────────────────────

    /** @return BelongsTo<Donor, $this> */
    public function donor(): BelongsTo
    {
        return $this->belongsTo(Donor::class);
    }

    /** @return BelongsTo<Cause, $this> */
    public function cause(): BelongsTo
    {
        return $this->belongsTo(Cause::class);
    }

    /** @return BelongsTo<DonationPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(DonationPlan::class, 'donation_plan_id');
    }

    /** @return HasMany<SubscriptionCharge, $this> */
    public function charges(): HasMany
    {
        return $this->hasMany(SubscriptionCharge::class)->orderByDesc('scheduled_on');
    }

    /** @return HasMany<Donation, $this> */
    public function donations(): HasMany
    {
        return $this->hasMany(Donation::class);
    }

    // ── Scheduling ───────────────────────────────────────────────────────────

    /** The next date after a given one, per the interval. */
    public function advanceFrom(Carbon $from): Carbon
    {
        return match ($this->interval) {
            self::INTERVAL_WEEKLY => $from->copy()->addWeek(),
            self::INTERVAL_QUARTERLY => $from->copy()->addMonthsNoOverflow(3),
            self::INTERVAL_ANNUALLY => $from->copy()->addYearNoOverflow(),
            // addMonthsNoOverflow, not addMonths: a gift set up on the 31st must
            // fall on the 28th in February and NOT skip to 3 March, which would
            // silently move every subsequent charge date.
            default => $from->copy()->addMonthNoOverflow(),
        };
    }

    /**
     * Whether this subscription may be charged today.
     *
     * The reusability check is the Ghana caveat made operational: mobile-money
     * authorizations are not reliably reusable, and attempting one that is not
     * fails the donor's gift every month for no reason. Better to skip it and
     * surface it than to generate a monthly failure.
     */
    public function isDueToday(?Carbon $on = null): bool
    {
        $on ??= now();

        if (! $this->status->isChargeable()) {
            return false;
        }

        if ($this->next_charge_on === null || $this->next_charge_on->gt($on)) {
            return false;
        }

        if ($this->driver === self::DRIVER_GATEWAY) {
            // Paystack owns this schedule; charging it ourselves would take the
            // money twice.
            return false;
        }

        return $this->authorization_reusable && filled($this->authorization_code);
    }

    /** Why a due subscription is not chargeable, for the administrator. */
    public function blockedReason(): ?string
    {
        if ($this->driver === self::DRIVER_GATEWAY) {
            return 'Paystack owns this schedule.';
        }

        if (blank($this->authorization_code)) {
            return 'No stored authorization — the donor must give again to re-establish it.';
        }

        if (! $this->authorization_reusable) {
            return 'The stored authorization is not reusable. Mobile-money authorizations often '
                .'are not; the donor must give again, or move to a card.';
        }

        return null;
    }

    // ── Lifecycle ────────────────────────────────────────────────────────────

    public function recordSuccess(Donation $donation): void
    {
        static::whereKey($this->getKey())->update([
            'charge_count' => DB::raw('charge_count + 1'),
            'total_charged_minor' => DB::raw('total_charged_minor + '.(int) $donation->amount->toMinor()),
            // Reset on ANY success: three failures last year should not suspend
            // a subscription that has been paying fine since.
            'failed_attempts' => 0,
            'status' => SubscriptionStatus::Active->value,
            'next_charge_on' => $this->advanceFrom($this->next_charge_on ?? now())->toDateString(),
            'updated_at' => now(),
        ]);

        $this->refresh();
    }

    /**
     * Record a failed cycle and decide whether to keep trying.
     *
     * Suspends after the configured number of consecutive failures rather than
     * retrying for ever. Hammering an expired card every month is how a charity
     * ends up on a card network's watch list — and by then the donor has
     * usually moved on anyway.
     */
    public function recordFailure(string $reason, int $maxAttempts = 3): void
    {
        $attempts = $this->failed_attempts + 1;

        $this->forceFill([
            'failed_attempts' => $attempts,
            'status' => $attempts >= $maxAttempts
                ? SubscriptionStatus::Paused
                : SubscriptionStatus::Failing,
            'cancel_reason' => $attempts >= $maxAttempts ? $reason : $this->cancel_reason,
            // Retry on the next cycle rather than immediately: a decline today
            // is rarely different tomorrow.
            'next_charge_on' => $this->advanceFrom($this->next_charge_on ?? now())->toDateString(),
        ])->save();
    }

    public function pause(string $reason = ''): void
    {
        $this->forceFill([
            'status' => SubscriptionStatus::Paused,
            'cancel_reason' => $reason !== '' ? $reason : null,
        ])->save();
    }

    public function resume(): void
    {
        if ($this->status->isFinished()) {
            throw new RuntimeException(
                'A cancelled subscription cannot be resumed. The donor set up a commitment and '
                .'ended it; restarting it without asking would be taking money they stopped.'
            );
        }

        $this->forceFill([
            'status' => SubscriptionStatus::Active,
            'failed_attempts' => 0,
            'next_charge_on' => max(now(), $this->next_charge_on ?? now())->toDateString(),
        ])->save();
    }

    public function cancel(string $reason = ''): void
    {
        $this->forceFill([
            'status' => SubscriptionStatus::Cancelled,
            'ended_on' => now()->toDateString(),
            'next_charge_on' => null,
            'cancel_reason' => $reason !== '' ? $reason : null,
        ])->save();
    }

    public function totalCharged(): Money
    {
        return $this->total_charged ?? Money::zero($this->currency);
    }

    /** Due today, and chargeable. */
    #[Scope]
    protected function due(Builder $query, ?Carbon $on = null): void
    {
        $on ??= now();

        $query->whereIn('status', [SubscriptionStatus::Active->value, SubscriptionStatus::Failing->value])
            ->where('driver', self::DRIVER_MANAGED)
            ->whereNotNull('next_charge_on')
            ->whereDate('next_charge_on', '<=', $on)
            ->where('authorization_reusable', true)
            ->whereNotNull('authorization_code');
    }

    /**
     * Due, but blocked — usually by an authorization that cannot be reused.
     *
     * Reported rather than silently skipped: a subscription nobody can charge
     * is a donor who thinks they are giving and is not.
     */
    #[Scope]
    protected function blocked(Builder $query, ?Carbon $on = null): void
    {
        $on ??= now();

        $query->whereIn('status', [SubscriptionStatus::Active->value, SubscriptionStatus::Failing->value])
            ->where('driver', self::DRIVER_MANAGED)
            ->whereNotNull('next_charge_on')
            ->whereDate('next_charge_on', '<=', $on)
            ->where(fn (Builder $q) => $q->where('authorization_reusable', false)
                ->orWhereNull('authorization_code'));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'amount_minor', 'interval', 'next_charge_on', 'cause_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('subscription');
    }
}
