<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ApiToken>
 *
 * Note this writes a hash of a throwaway string rather than a usable token.
 * A factory that handed out working credentials would be a factory somebody
 * eventually used in a seeder.
 */
class ApiTokenFactory extends Factory
{
    protected $model = ApiToken::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $plain = Str::random(48);

        return [
            'name' => fake()->words(2, true),
            'token_hash' => ApiToken::hash($plain),
            'prefix' => substr($plain, 0, 12),
            'user_id' => User::factory(),
            'abilities' => [],
            'expires_at' => now()->addDays(90),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (): array => ['expires_at' => now()->subDay()]);
    }
}
