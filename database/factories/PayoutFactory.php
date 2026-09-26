<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Division;
use App\Models\Payout;
use App\ValueObjects\Money;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payout>
 */
class PayoutFactory extends Factory
{
    protected $model = Payout::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'division_id' => Division::factory(),
            'payee_name' => fake()->company().' Basic School',
            'amount' => Money::ofMinor(1200000),
            'category' => Payout::CATEGORY_SCHOOL_FEES,
            'method' => 'bank_transfer',
            'purpose' => 'School fees for 40 children, 2026 second term.',
        ];
    }
}
