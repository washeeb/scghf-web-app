<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\FeatureFlag;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FeatureFlag>
 */
class FeatureFlagFactory extends Factory
{
    protected $model = FeatureFlag::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            // A key that genuinely exists in config/features.php — the model
            // refuses anything else, which is the behaviour being relied on.
            'key' => 'blog_comments',
            'is_enabled' => true,
            'reason' => 'Trialling comments on the blog.',
            'expires_at' => now()->addDays(30),
        ];
    }

    public function lapsed(): static
    {
        return $this->state(fn (): array => ['expires_at' => now()->subDay()]);
    }
}
