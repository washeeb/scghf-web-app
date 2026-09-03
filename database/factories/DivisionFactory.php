<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Division;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Division>
 */
class DivisionFactory extends Factory
{
    protected $model = Division::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true).' Initiative';

        return [
            'name' => Str::title($name),
            'slug' => Str::slug($name),
            'tagline' => fake()->words(2, true),
            'summary' => fake()->sentence(12),
            'colour_token' => 'division-lifespring',
            'icon' => 'heroicon-o-heart',
            'sort_order' => 0,
            'is_active' => true,
            'is_locked' => false,
        ];
    }

    /** A seeded, structural division that refuses deletion. */
    public function locked(): static
    {
        return $this->state(fn (): array => ['is_locked' => true]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
