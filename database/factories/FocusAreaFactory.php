<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Division;
use App\Models\FocusArea;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<FocusArea>
 */
class FocusAreaFactory extends Factory
{
    protected $model = FocusArea::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = Str::title(fake()->unique()->words(3, true));

        return [
            'division_id' => Division::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => fake()->sentence(10),
            'sort_order' => 0,
            'is_active' => true,
        ];
    }
}
