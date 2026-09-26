<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductVariant>
 */
class ProductVariantFactory extends Factory
{
    protected $model = ProductVariant::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'sku' => 'SCGHF-'.fake()->unique()->bothify('??##??'),
            'name' => 'Standard',
            // GH₵ 45.00 in integer pesewas
            'price' => 4_500,
            'currency' => 'GHS',
            'tracks_stock' => true,
            'is_active' => true,
        ];
    }

    /** Nothing left on the shelf. */
    public function outOfStock(): static
    {
        return $this->state(fn (): array => ['stock_on_hand' => 0]);
    }

    public function untracked(): static
    {
        return $this->state(fn (): array => ['tracks_stock' => false]);
    }
}
