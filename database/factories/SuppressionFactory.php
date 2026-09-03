<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Suppression;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Suppression>
 */
class SuppressionFactory extends Factory
{
    protected $model = Suppression::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'channel' => Suppression::CHANNEL_EMAIL,
            'address' => fake()->unique()->safeEmail(),
            'scope' => Suppression::SCOPE_ALL,
            'reason' => Suppression::REASON_HARD_BOUNCE,
            'source' => 'webhook',
            'suppressed_at' => now(),
        ];
    }

    /** The scope that stops appeals and lets receipts through. */
    public function unsubscribe(): static
    {
        return $this->state(fn (): array => [
            'scope' => Suppression::SCOPE_MARKETING,
            'reason' => Suppression::REASON_UNSUBSCRIBE,
            'source' => 'manual',
        ]);
    }
}
