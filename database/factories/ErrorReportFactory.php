<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ErrorReport;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ErrorReport>
 */
class ErrorReportFactory extends Factory
{
    protected $model = ErrorReport::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'fingerprint' => hash('sha256', (string) fake()->unique()->uuid()),
            'exception_class' => 'RuntimeException',
            'message' => 'Something went wrong.',
            'file' => 'app/Http/Controllers/PageController.php',
            'line' => 42,
            'severity' => ErrorReport::SEVERITY_ERROR,
            'first_seen_at' => now()->subDay(),
            'last_seen_at' => now(),
        ];
    }

    public function resolved(): static
    {
        return $this->state(fn (): array => ['resolved_at' => now()]);
    }
}
