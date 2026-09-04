<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Event;
use App\Models\EventTicket;
use App\ValueObjects\Money;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventTicket>
 */
class EventTicketFactory extends Factory
{
    protected $model = EventTicket::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'name' => 'General admission',
            'price' => Money::ofMinor(5000),
            'quantity' => 100,
            'max_per_order' => 4,
        ];
    }

    /** A free place that still needs reserving — the outreach-event case. */
    public function free(): static
    {
        return $this->state(fn (): array => ['price' => Money::ofMinor(0)]);
    }
}
