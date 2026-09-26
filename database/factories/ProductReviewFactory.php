<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductReview;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductReview>
 */
class ProductReviewFactory extends Factory
{
    protected $model = ProductReview::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'author_name' => fake()->name(),
            'author_email' => fake()->unique()->safeEmail(),
            'rating' => 5,
            'body' => 'Good quality, arrived quickly.',
            // Pending, which is where a real review starts. Moderation happens
            // before publication, not after.
        ];
    }
}
