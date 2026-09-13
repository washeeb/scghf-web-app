<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;

/**
 * A row in `failed_jobs`, read-only except for the two things you can do
 * with one: try it again, or let it go.
 *
 * Laravel has no model for the table because it expects the terminal;
 * this host has no terminal a trustee will ever open. Retry and forget go
 * through the artisan commands so the behaviour is Laravel's own.
 */
class FailedJob extends Model
{
    protected $table = 'failed_jobs';

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['failed_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** The job's class name, out of the serialised payload. */
    public function displayName(): string
    {
        $payload = json_decode((string) $this->payload, true);

        return (string) ($payload['displayName'] ?? $payload['job'] ?? __('Unknown job'));
    }

    /** The first line of the exception, which is usually the one that matters. */
    public function reason(): string
    {
        return (string) strtok((string) $this->exception, "\n");
    }

    public function retry(): void
    {
        Artisan::call('queue:retry', ['id' => [$this->uuid]]);
    }

    public function forget(): void
    {
        Artisan::call('queue:forget', ['id' => $this->uuid]);
    }
}
