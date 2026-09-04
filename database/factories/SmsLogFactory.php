<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SmsLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SmsLog>
 */
class SmsLogFactory extends Factory
{
    protected $model = SmsLog::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'template_key' => 'test.message',
            'category' => 'transactional',
            'to_number' => '+2332'.fake()->numerify('#######'),
            'network' => 'mtn',
            'sender_id' => 'GreaterHope',
            'body' => 'A short message.',
            'encoding' => 'gsm7',
            'character_count' => 16,
            'segments' => 1,
            'estimated_cost_minor' => 4,
            'driver' => 'log',
            /*
             * `sent`, not `delivered`. Handing a message to a provider is not
             * evidence that a network accepted it, and a factory that defaulted
             * to delivered would let the distinction rot.
             */
            'status' => SmsLog::STATUS_SENT,
            'sent_at' => now(),
        ];
    }

    public function delivered(): static
    {
        return $this->state(fn (): array => [
            'status' => SmsLog::STATUS_DELIVERED,
            'delivered_at' => now(),
        ]);
    }
}
