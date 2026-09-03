<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ProductCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProductCategory>
 */
class ProductCategoryFactory extends Factory
{
    protected $model = ProductCategory::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = Str::title(fake()->unique()->words(2, true));

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'policy_key' => 'gifts',
            'is_active' => true,
        ];
    }

    /** A category the trustees have not covered in the agreed taxonomy. */
    public function outsidePolicy(): static
    {
        return $this->state(fn (): array => ['policy_key' => null]);
    }
}
