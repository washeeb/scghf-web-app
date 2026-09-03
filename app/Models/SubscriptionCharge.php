<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One cycle of a recurring gift, attempted or not.
 *
 * A row exists for a cycle that was skipped or failed as well as one that paid.
 * "Nothing happened in April, and here is why" is an answer a donor and a
 * trustee can both use; a missing row is not.
 */
class SubscriptionCharge extends Model
{
    use HasFactory;

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'subscription_id', 'donation_id', 'scheduled_on', 'attempted_at',
        'status', 'amount', 'currency', 'failure_reason', 'attempt',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => self::STATUS_SCHEDULED,
        'currency' => 'GHS',
        'attempt' => 1,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'scheduled_on' => 'date',
            'attempted_at' => 'datetime',
            'amount' => MoneyCast::class.':amount_minor,currency',
        ];
    }

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /** @return BelongsTo<Donation, $this> */
    public function donation(): BelongsTo
    {
        return $this->belongsTo(Donation::class);
    }

    public function markSucceeded(Donation $donation): void
    {
        $this->forceFill([
            'status' => self::STATUS_SUCCEEDED,
            'donation_id' => $donation->getKey(),
            'attempted_at' => now(),
            'failure_reason' => null,
        ])->save();
    }

    public function markFailed(string $reason): void
    {
        $this->forceFill([
            'status' => self::STATUS_FAILED,
            'attempted_at' => now(),
            'failure_reason' => $reason,
            'attempt' => $this->attempt + 1,
        ])->save();
    }

    /**
     * Not attempted, and why.
     *
     * Usually an authorization that cannot be reused. Recorded rather than
     * passed over, because a donor who believes they are giving monthly and is
     * not deserves to be told.
     */
    public function markSkipped(string $reason): void
    {
        $this->forceFill([
            'status' => self::STATUS_SKIPPED,
            'attempted_at' => now(),
            'failure_reason' => $reason,
        ])->save();
    }

    #[Scope]
    protected function failed(Builder $query): void
    {
        $query->where('status', self::STATUS_FAILED);
    }
}
