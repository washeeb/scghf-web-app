<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PageStatus;
use App\Models\Page;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Page>
 */
class PageFactory extends Factory
{
    protected $model = Page::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $title = fake()->unique()->sentence(3);

        return [
            'title' => rtrim($title, '.'),
            'slug' => Str::slug($title),
            'excerpt' => fake()->sentence(),
            'template' => 'default',
            /*
             * Draft by default, which is the state a page is really created in
             * — content is written before it is published. A factory handing
             * out published pages would let `isLive()` and every gate that
             * depends on it rot untested.
             */
            'status' => PageStatus::Draft,
            'show_in_sitemap' => true,
            'show_in_search' => true,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (): array => [
            'status' => PageStatus::Published,
            'published_at' => now()->subDay(),
        ]);
    }

    /** Live at a future date, without anybody touching it. */
    public function scheduled(): static
    {
        return $this->state(fn (): array => [
            'status' => PageStatus::Scheduled,
            'published_at' => now()->addWeek(),
        ]);
    }
}
