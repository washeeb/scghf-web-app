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
use Illuminate\Support\Str;

/**
 * A named recurring giving option.
 *
 * "Sponsor a child — GH₵ 50 a month" fixes the amount; "Monthly giving" leaves
 * it to the donor. `amount_minor` is nullable for exactly that reason: zero
 * would mean free, which is a different and wrong thing.
 */
class DonationPlan extends Model
{
    use BelongsToDivision;
    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    protected $fillable = [
        'cause_id', 'division_id', 'name', 'slug', 'description',
        'amount', 'currency', 'interval', 'paystack_plan_code',
        'sort_order', 'is_active',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'currency' => 'GHS',
        'interval' => Subscription::INTERVAL_MONTHLY,
        'sort_order' => 0,
        'is_active' => true,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'amount' => MoneyCast::class.':amount_minor,currency',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $plan): void {
            if (blank($plan->slug)) {
                $plan->slug = Str::slug($plan->name);
            }

            $plan->slug = Str::slug($plan->slug);
        });
    }

    /** @return array<int, string> */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** @return BelongsTo<Cause, $this> */
    public function cause(): BelongsTo
    {
        return $this->belongsTo(Cause::class);
    }

    /** @return HasMany<Subscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /** Whether the donor chooses the amount rather than the plan fixing it. */
    public function isOpenAmount(): bool
    {
        return $this->amount_minor === null;
    }

    public function fixedAmount(): ?Money
    {
        return $this->amount;
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true)->orderBy('sort_order')->orderBy('name');
    }
}
