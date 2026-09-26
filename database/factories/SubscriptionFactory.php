<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SubscriptionStatus;
use App\Models\Cause;
use App\Models\Donor;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'donor_id' => Donor::factory(),
            'cause_id' => Cause::factory(),
            // GH₵ 50.00 a month, the commonest standing gift
            'amount' => 5_000,
            'currency' => 'GHS',
            'interval' => Subscription::INTERVAL_MONTHLY,
            'driver' => Subscription::DRIVER_MANAGED,
            'status' => SubscriptionStatus::Active,
            'started_on' => now()->subMonth()->toDateString(),
            'next_charge_on' => now()->toDateString(),
            'authorization_code' => 'AUTH_reusable_'.fake()->numerify('######'),
            'authorization_reusable' => true,
            'channel' => 'card',
            'card_last4' => '4321',
        ];
    }

    /** A mobile-money authorization that cannot be charged again. */
    public function notReusable(): static
    {
        return $this->state(fn (): array => [
            'authorization_reusable' => false,
            'channel' => 'mobile_money',
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (): array => [
            'status' => SubscriptionStatus::Cancelled,
            'ended_on' => now()->toDateString(),
            'next_charge_on' => null,
        ]);
    }
}
