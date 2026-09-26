<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Volunteer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Volunteer>
 */
class VolunteerFactory extends Factory
{
    protected $model = Volunteer::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'full_name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '+2332'.fake()->numerify('#######'),
            'role' => 'Reading club helper',
            'status' => Volunteer::STATUS_ACTIVE,
            'is_cleared' => true,
            'clearance_expires_on' => now()->addYear()->toDateString(),
            'involves_vulnerable_contact' => true,
            'started_on' => now()->subMonths(3)->toDateString(),
        ];
    }

    public function noVulnerableContact(): static
    {
        return $this->state(fn (): array => ['involves_vulnerable_contact' => false, 'is_cleared' => false, 'clearance_expires_on' => null]);
    }
}
