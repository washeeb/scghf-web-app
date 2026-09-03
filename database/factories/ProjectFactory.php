<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ProjectStatus;
use App\Models\Division;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    protected $model = Project::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $title = Str::title(fake()->unique()->words(4, true));

        return [
            'division_id' => Division::factory(),
            'title' => $title,
            'slug' => Str::slug($title),
            'summary' => fake()->sentence(14),
            'description' => fake()->paragraphs(3, true),
            'status' => ProjectStatus::Active,
            'starts_on' => now()->subMonths(6)->toDateString(),
            'ends_on' => now()->addMonths(6)->toDateString(),
            // Integer pesewas, never a float — GH₵ 25,000.00
            'budget' => 2_500_000,
            'currency' => 'GHS',
            'is_published' => true,
            'published_at' => now()->subMonth(),
        ];
    }

    /** Foundation-wide: belongs to no single division. */
    public function foundationWide(): static
    {
        return $this->state(fn (): array => ['division_id' => null]);
    }

    public function draft(): static
    {
        return $this->state(fn (): array => [
            'is_published' => false,
            'published_at' => null,
            'status' => ProjectStatus::Planned,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (): array => [
            'status' => ProjectStatus::Completed,
            'ends_on' => now()->subMonth()->toDateString(),
        ]);
    }
}
