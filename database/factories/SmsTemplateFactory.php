<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SmsTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SmsTemplate>
 */
class SmsTemplateFactory extends Factory
{
    protected $model = SmsTemplate::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'key' => 'test.'.fake()->unique()->slug(2),
            'name' => fake()->sentence(3),
            'category' => SmsTemplate::CATEGORY_TRANSACTIONAL,
            'body' => 'Hello {{name}}, your reference is {{reference}}.',
            'available_variables' => ['name', 'reference'],
            'required_variables' => ['name'],
            'max_segments' => 2,
            'is_active' => true,
        ];
    }

    public function marketing(): static
    {
        return $this->state(fn (): array => ['category' => SmsTemplate::CATEGORY_MARKETING]);
    }
}
