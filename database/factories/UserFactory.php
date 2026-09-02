<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\UserType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    /** Hashed once per process rather than per user — bcrypt is deliberately slow. */
    protected static ?string $password = null;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            // A real Ghanaian mobile shape: 0 + network prefix + 7 digits.
            'phone' => '0'.fake()->randomElement(['24', '54', '55', '59', '20', '50', '26', '56', '27', '57'])
                .fake()->numerify('#######'),
            'type' => UserType::Donor,
            'locale' => 'en',
            'timezone' => 'Africa/Accra',
            'is_active' => true,
            'accepts_email_marketing' => fake()->boolean(60),
            'accepts_sms_marketing' => fake()->boolean(40),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (): array => ['email_verified_at' => null]);
    }

    public function staff(): static
    {
        return $this->state(fn (): array => [
            'type' => UserType::Staff,
            'job_title' => fake()->jobTitle(),
        ]);
    }

    public function donor(): static
    {
        return $this->state(fn (): array => ['type' => UserType::Donor]);
    }

    public function withTwoFactor(): static
    {
        return $this->state(fn (): array => [
            'two_factor_secret' => Str::random(32),
            'two_factor_recovery_codes' => array_map(
                fn (): string => Str::random(10).'-'.Str::random(10),
                range(1, 8),
            ),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    public function suspended(string $reason = 'Suspended during testing'): static
    {
        return $this->state(fn (): array => [
            'suspended_at' => now(),
            'suspended_reason' => $reason,
            'is_active' => false,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
