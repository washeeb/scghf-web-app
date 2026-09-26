<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = Str::title(fake()->unique()->words(3, true)).' Mug';

        return [
            'product_category_id' => ProductCategory::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
            'summary' => 'Branded foundation merchandise.',
            'description' => 'A sturdy ceramic mug carrying the foundation logo.',
            'product_type' => Product::TYPE_PHYSICAL,
            'is_published' => true,
            'published_at' => now()->subDay(),
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (): array => ['is_published' => false, 'published_at' => null]);
    }

    public function digital(): static
    {
        return $this->state(fn (): array => ['product_type' => Product::TYPE_DIGITAL]);
    }

    /** Text that trips the FDA keyword screen. */
    public function regulated(): static
    {
        return $this->state(fn (): array => [
            'is_published' => false,
            'published_at' => null,
            'description' => 'Herbal remedy and vitamin supplement for daily wellbeing.',
        ]);
    }
}
