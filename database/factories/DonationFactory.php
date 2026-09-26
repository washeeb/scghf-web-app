<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DonationStatus;
use App\Models\Cause;
use App\Models\Donation;
use App\Models\Donor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Donation>
 */
class DonationFactory extends Factory
{
    protected $model = Donation::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'donor_id' => Donor::factory(),
            'cause_id' => Cause::factory(),
            // GH₵ 250.00 in integer pesewas
            'amount' => 25_000,
            'currency' => 'GHS',
            'status' => DonationStatus::Pending,
            'donor_name' => fake()->name(),
            'donor_email' => fake()->safeEmail(),
            'channel' => 'mobile_money',
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (): array => [
            'status' => DonationStatus::Completed,
            'paid_at' => now(),
        ]);
    }

    public function anonymous(): static
    {
        return $this->state(fn (): array => ['is_anonymous' => true]);
    }

    public function inTributeTo(string $name): static
    {
        return $this->state(fn (): array => [
            'tribute_type' => 'in_memory_of',
            'tribute_name' => $name,
        ]);
    }
}
