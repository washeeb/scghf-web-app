<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\LoginOutcome;
use App\Models\LoginHistory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LoginHistory>
 */
class LoginHistoryFactory extends Factory
{
    protected $model = LoginHistory::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'email_attempted' => fake()->safeEmail(),
            'outcome' => LoginOutcome::Success,
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
            'device_type' => fake()->randomElement(['desktop', 'mobile', 'tablet']),
            'platform' => fake()->randomElement(['Windows', 'Android', 'iOS', 'macOS']),
            'browser' => fake()->randomElement(['Chrome', 'Firefox', 'Safari', 'Edge']),
            'country_code' => 'GH',
            'was_two_factor_used' => false,
            'is_new_device' => false,
            'created_at' => now(),
        ];
    }

    public function failed(): static
    {
        return $this->state(fn (): array => ['outcome' => LoginOutcome::Failed]);
    }

    /** An attempt against an address with no account — the credential-stuffing case. */
    public function unknownAccount(): static
    {
        return $this->state(fn (): array => [
            'user_id' => null,
            'outcome' => LoginOutcome::Failed,
        ]);
    }

    public function fromIp(string $ip): static
    {
        return $this->state(fn (): array => ['ip_address' => $ip]);
    }

    public function newDevice(): static
    {
        return $this->state(fn (): array => ['is_new_device' => true]);
    }
}
