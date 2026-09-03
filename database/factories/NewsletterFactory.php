<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Newsletter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Newsletter>
 */
class NewsletterFactory extends Factory
{
    protected $model = Newsletter::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $topic = fake()->unique()->slug(1);

        return [
            'key' => $topic,
            'topic' => $topic,
            'name' => fake()->sentence(2),
            'cadence' => 'monthly',
            'is_active' => true,
        ];
    }
}
