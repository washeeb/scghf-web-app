<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Consent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Consent>
 */
class ConsentFactory extends Factory
{
    protected $model = Consent::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'consent_type' => Consent::TYPE_STORY,
            'scope' => Consent::SCOPE_WEBSITE,
            'granted_by_name' => fake()->name(),
            'granted_by_relationship' => Consent::BY_SELF,
            'is_minor' => false,
            'granted_at' => now()->subMonth(),
        ];
    }

    public function ofType(string $type): static
    {
        return $this->state(fn (): array => ['consent_type' => $type]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'granted_at' => now()->subYears(2),
            'expires_at' => now()->subDay(),
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn (): array => [
            'revoked_at' => now()->subDay(),
            'revoked_reason' => 'Withdrawn by the beneficiary.',
        ]);
    }

    /** Given by a guardian, because the subject is a child. */
    public function forMinor(): static
    {
        return $this->state(fn (): array => [
            'is_minor' => true,
            'granted_by_relationship' => Consent::BY_GUARDIAN,
            'guardian_name' => fake()->name(),
        ]);
    }
}
