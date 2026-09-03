<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CauseStatus;
use App\Models\Cause;
use App\Models\Division;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Cause>
 */
class CauseFactory extends Factory
{
    protected $model = Cause::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $title = Str::title(fake()->unique()->words(4, true));

        return [
            'division_id' => Division::factory(),
            'title' => $title,
            'slug' => Str::slug($title),
            'summary' => fake()->sentence(12),
            // GH₵ 50,000.00 in integer pesewas
            'goal' => 5_000_000,
            'currency' => 'GHS',
            'status' => CauseStatus::Active,
            'is_published' => true,
            'published_at' => now()->subWeek(),
        ];
    }

    public function generalFund(): static
    {
        return $this->state(fn (): array => [
            'title' => 'General Fund',
            'slug' => 'general-fund',
            'division_id' => null,
            'is_general_fund' => true,
            'is_locked' => true,
            'goal' => null,
        ]);
    }

    public function draft(): static
    {
        return $this->state(fn (): array => [
            'status' => CauseStatus::Draft,
            'is_published' => false,
            'published_at' => null,
        ]);
    }

    /** Marked by the trustees as a qualifying worthwhile cause. */
    public function taxDeductible(): static
    {
        return $this->state(fn (): array => ['is_tax_deductible' => true]);
    }

    public function closed(): static
    {
        return $this->state(fn (): array => [
            'ends_on' => now()->subDay()->toDateString(),
        ]);
    }
}
