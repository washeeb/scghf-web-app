<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Division;
use App\Models\ImpactMetric;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ImpactMetric>
 */
class ImpactMetricFactory extends Factory
{
    protected $model = ImpactMetric::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = Str::title(fake()->unique()->words(3, true));

        return [
            'division_id' => Division::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
            'unit' => 'items',
            'value_type' => ImpactMetric::TYPE_INTEGER,
            'aggregation' => ImpactMetric::AGGREGATION_SUM,
            'counts_people' => false,
            'is_public' => true,
        ];
    }

    /** A metric that counts people, and is therefore disclosure-controlled. */
    public function countsPeople(): static
    {
        return $this->state(fn (): array => [
            'counts_people' => true,
            'unit' => 'beneficiaries',
        ]);
    }

    public function money(): static
    {
        return $this->state(fn (): array => [
            'value_type' => ImpactMetric::TYPE_MONEY,
            'unit' => null,
        ]);
    }
}
