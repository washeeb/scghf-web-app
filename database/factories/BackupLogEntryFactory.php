<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\BackupLogEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BackupLogEntry>
 */
class BackupLogEntryFactory extends Factory
{
    protected $model = BackupLogEntry::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'type' => BackupLogEntry::TYPE_BACKUP,
            'status' => BackupLogEntry::STATUS_COMPLETED,
            'destination' => 'local',
            'filename' => 'backups/2026-09-04-020000.zip',
            'size_bytes' => 180 * 1024 * 1024,
            'file_count' => 2400,
            'duration_seconds' => 95,
            'started_at' => now(),
            'finished_at' => now(),
        ];
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => BackupLogEntry::STATUS_FAILED,
            'error' => 'Could not connect to the database.',
        ]);
    }
}
