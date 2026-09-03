<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Event;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    protected $model = Event::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $title = Str::title(fake()->unique()->words(3, true)).' Outreach';

        return [
            'title' => $title,
            'slug' => Str::slug($title),
            'summary' => 'A community outreach afternoon.',
            'starts_at' => now()->addWeeks(2),
            'ends_at' => now()->addWeeks(2)->addHours(4),
            'venue_name' => 'Community Centre',
            'region' => 'Greater Accra',
            'registration_required' => true,
            'capacity' => 80,
            'is_published' => true,
            'published_at' => now()->subDay(),
        ];
    }

    public function past(): static
    {
        return $this->state(fn (): array => [
            'starts_at' => now()->subYears(3),
            'ends_at' => now()->subYears(3)->addHours(4),
        ]);
    }

    public function full(): static
    {
        return $this->state(fn (): array => ['capacity' => 2, 'registered_count' => 2]);
    }
}
