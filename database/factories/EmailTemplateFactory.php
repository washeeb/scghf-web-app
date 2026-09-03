<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\EmailTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailTemplate>
 */
class EmailTemplateFactory extends Factory
{
    protected $model = EmailTemplate::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'key' => 'test.'.fake()->unique()->slug(2),
            'name' => fake()->sentence(3),
            'category' => EmailTemplate::CATEGORY_TRANSACTIONAL,
            'subject' => 'Hello {{name}}',
            'body_html' => '<p>Hello {{name}}, this is about {{thing}}.</p>',
            'body_text' => 'Hello {{name}}, this is about {{thing}}.',
            'available_variables' => ['name', 'thing'],
            'required_variables' => ['name'],
            'is_active' => true,
        ];
    }

    public function marketing(): static
    {
        return $this->state(fn (): array => ['category' => EmailTemplate::CATEGORY_MARKETING]);
    }

    public function locked(): static
    {
        return $this->state(fn (): array => ['is_locked' => true]);
    }
}
