<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\EmailLog;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailLog>
 */
class EmailLogFactory extends Factory
{
    protected $model = EmailLog::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'template_key' => 'test.message',
            'category' => 'transactional',
            'to_address' => fake()->unique()->safeEmail(),
            'subject' => fake()->sentence(4),
            'status' => EmailLog::STATUS_SENT,
            'sent_at' => now(),
        ];
    }

    /** For exercising the hourly throttle, which counts real `sent_at` values. */
    public function sentAt(DateTimeInterface $at): static
    {
        return $this->state(fn (): array => ['sent_at' => $at, 'created_at' => $at]);
    }
}
