<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Story;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Story>
 */
class StoryFactory extends Factory
{
    protected $model = Story::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $title = Str::title(fake()->unique()->words(5, true));

        return [
            'title' => $title,
            'slug' => Str::slug($title),
            'summary' => fake()->sentence(12),
            'body' => fake()->paragraphs(4, true),
            // Unpublished by default. Publication needs consent, and a factory
            // that published by default would make every test that forgets to
            // set consent fail for the wrong reason.
            'is_published' => false,
        ];
    }

    public function withPseudonym(string $name = 'Akosua'): static
    {
        return $this->state(fn (): array => [
            'subject_display_name' => $name,
            'uses_pseudonym' => true,
        ]);
    }

    public function withRealName(string $name = 'Ama Serwaa Boateng'): static
    {
        return $this->state(fn (): array => [
            'subject_display_name' => $name,
            'uses_pseudonym' => false,
        ]);
    }
}
