<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Beneficiary;
use App\Models\Division;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Beneficiary>
 */
class BeneficiaryFactory extends Factory
{
    protected $model = Beneficiary::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'division_id' => Division::factory(),
            'status' => Beneficiary::STATUS_SUBMITTED,
            'full_name' => fake()->name(),
            'phone' => '+2332'.fake()->numerify('#######'),
            'email' => fake()->safeEmail(),
            'ghana_card_number' => 'GHA-'.fake()->numerify('#########').'-'.fake()->numerify('#'),
            'date_of_birth' => fake()->dateTimeBetween('-60 years', '-6 years')->format('Y-m-d'),
            'address' => fake()->streetAddress(),
            'community' => fake()->city(),
            'district' => 'Kumasi Metropolitan',
            'region' => 'Ashanti',
            'gender' => fake()->randomElement(['female', 'male']),
            'medical_notes' => 'Referral letter from the district hospital.',
            'application_narrative' => fake()->paragraph(),
            'case_notes' => 'Home visit completed.',
            'intake_ip' => fake()->ipv4(),
            'submitted_at' => now()->subMonths(2),
            'last_activity_at' => now()->subMonths(2),
        ];
    }

    /** An assisted case, closed and past its six-year retention period. */
    public function dueForRetention(): static
    {
        return $this->state(fn (): array => [
            'status' => Beneficiary::STATUS_CLOSED,
            'assistance' => 473_500,
            'assisted_on' => now()->subYears(7)->toDateString(),
            'outcome' => 'assisted',
            'closed_at' => now()->subYears(7),
            'decided_at' => now()->subYears(7),
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn (): array => [
            'status' => Beneficiary::STATUS_CLOSED,
            'assistance' => 473_500,
            'assisted_on' => now()->subMonths(3)->toDateString(),
            'outcome' => 'assisted',
            'closed_at' => now()->subMonths(3),
        ]);
    }

    public function declined(): static
    {
        return $this->state(fn (): array => [
            'status' => Beneficiary::STATUS_DECLINED,
            'decided_at' => now()->subMonths(30),
            'outcome' => 'declined',
        ]);
    }

    public function withdrawn(): static
    {
        return $this->state(fn (): array => [
            'status' => Beneficiary::STATUS_WITHDRAWN,
            'last_activity_at' => now()->subMonths(18),
        ]);
    }
}
