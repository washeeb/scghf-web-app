<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Cause;
use App\Models\Pledge;
use App\ValueObjects\Money;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Pledge>
 */
class PledgeFactory extends Factory
{
    protected $model = Pledge::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'cause_id' => Cause::factory(),
            'pledger_name' => fake()->name(),
            'pledger_email' => fake()->unique()->safeEmail(),
            'amount' => Money::ofMinor(500000),
            'occasion' => Pledge::OCCASION_HARVEST,
            'due_on' => now()->addMonths(3)->toDateString(),
            // Off by default, because a pledge is not consent to be chased.
            'consent_to_remind' => false,
        ];
    }

    public function remindable(): static
    {
        return $this->state(fn (): array => ['consent_to_remind' => true]);
    }

    public function overdue(): static
    {
        return $this->state(fn (): array => ['due_on' => now()->subMonth()->toDateString()]);
    }
}
