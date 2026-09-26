<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 *
 * Present so tests can READ audit rows, not so they can write them. Anything
 * asserting on the chain must go through App\Support\AuditLogger — a row
 * created here carries no valid hash, which is exactly the state the verifier
 * exists to find.
 */
class AuditLogFactory extends Factory
{
    protected $model = AuditLog::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'event' => 'auth.login',
            'category' => 'auth',
            'severity' => AuditLog::SEVERITY_INFO,
            'description' => 'Signed in.',
            'occurred_at' => now(),
            'created_at' => now(),
            'hash' => hash('sha256', (string) fake()->unique()->uuid()),
        ];
    }
}
