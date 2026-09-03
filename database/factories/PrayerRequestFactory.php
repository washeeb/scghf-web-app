<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PrayerRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PrayerRequest>
 */
class PrayerRequestFactory extends Factory
{
    protected $model = PrayerRequest::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'category' => PrayerRequest::CATEGORY_HEALTH,
            'request' => 'Please pray for my mother, who is unwell.',
            // Confidential by default, matching the model.
            'is_confidential' => true,
            'consent_to_publish' => false,
        ];
    }

    /** Explicitly agreed to be published. */
    public function publishable(): static
    {
        return $this->state(fn (): array => [
            'is_confidential' => false,
            'consent_to_publish' => true,
            'consent_text' => 'I agree that this request may be shared publicly.',
        ]);
    }

    public function anonymous(): static
    {
        return $this->state(fn (): array => ['is_anonymous' => true]);
    }
}
