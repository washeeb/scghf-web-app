<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PaymentStatus;
use App\Models\PaymentTransaction;
use App\Payments\PaymentManager;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentTransaction>
 */
class PaymentTransactionFactory extends Factory
{
    protected $model = PaymentTransaction::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'gateway' => PaymentTransaction::GATEWAY_FAKE,
            'gateway_reference' => PaymentManager::generateReference(),
            // GH₵ 250.00 in integer pesewas
            'amount' => 25_000,
            'currency' => 'GHS',
            'status' => PaymentStatus::Pending,
            'customer_email' => fake()->safeEmail(),
            'initialised_at' => now(),
        ];
    }

    public function settled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PaymentStatus::Success,
            'amount_paid_minor' => $attributes['amount'] ?? 25_000,
            'currency_paid' => 'GHS',
            'paid_at' => now(),
            'verified_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => ['status' => PaymentStatus::Failed]);
    }

    /** A reference the fake gateway will settle one pesewa light. */
    public function shortSettling(): static
    {
        return $this->state(fn (): array => [
            'gateway_reference' => PaymentManager::generateReference().'-SHORT',
        ]);
    }

    public function declining(): static
    {
        return $this->state(fn (): array => [
            'gateway_reference' => PaymentManager::generateReference().'-FAIL',
        ]);
    }
}
