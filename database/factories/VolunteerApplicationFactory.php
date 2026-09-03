<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\VolunteerApplication;
use App\Models\VolunteerOpportunity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VolunteerApplication>
 */
class VolunteerApplicationFactory extends Factory
{
    protected $model = VolunteerApplication::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'volunteer_opportunity_id' => VolunteerOpportunity::factory(),
            'status' => VolunteerApplication::STATUS_DRAFT,
            'full_name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '+2332'.fake()->numerify('#######'),
            'date_of_birth' => fake()->dateTimeBetween('-55 years', '-19 years')->format('Y-m-d'),
            'address' => fake()->streetAddress(),
            'region' => 'Greater Accra',
            'motivation' => 'I would like to help with the education programme.',
            'declaration_agreed' => true,
            'declaration_text' => 'I have read the safeguarding policy and disclosed any relevant convictions.',
        ];
    }

    public function declined(): static
    {
        return $this->state(fn (): array => [
            'status' => VolunteerApplication::STATUS_DECLINED,
            'decided_at' => now()->subMonths(18),
        ]);
    }

    public function withoutDeclaration(): static
    {
        return $this->state(fn (): array => [
            'declaration_agreed' => false,
            'declaration_text' => null,
        ]);
    }
}
