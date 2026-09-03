<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Donor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Donor>
 */
class DonorFactory extends Factory
{
    protected $model = Donor::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '+2332'.fake()->unique()->numerify('#######'),
            'donor_type' => Donor::TYPE_INDIVIDUAL,
            'consent_email' => true,
            'consent_text' => 'I agree to receive updates from the foundation.',
            'consent_at' => now(),
        ];
    }

    public function organisation(): static
    {
        return $this->state(fn (): array => [
            'donor_type' => Donor::TYPE_ORGANISATION,
            'organisation_name' => fake()->company(),
        ]);
    }

    public function withoutConsent(): static
    {
        return $this->state(fn (): array => [
            'consent_email' => false,
            'consent_sms' => false,
            'consent_text' => null,
            'consent_at' => null,
        ]);
    }
}
