<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\BeneficiaryImpactRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BeneficiaryImpactRecord>
 */
class BeneficiaryImpactRecordFactory extends Factory
{
    protected $model = BeneficiaryImpactRecord::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'region' => 'Ashanti',
            'district' => 'Kumasi Metropolitan',
            'gender' => fake()->randomElement(['female', 'male']),
            'age_band' => fake()->randomElement(['6-12', '13-17', '18-24', '25-34']),
            'assistance_band' => '2,500.00 - 5,000.00',
            'assistance_period' => '2026-03',
            'outcome' => 'assisted',
        ];
    }
}
