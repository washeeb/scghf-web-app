<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\BackupLogEntry;
use App\Support\AuditLogger;
use Spatie\Backup\Events\BackupHasFailed;
use Spatie\Backup\Events\BackupWasSuccessful;
use Spatie\Backup\Events\BackupZipWasCreated;
use Throwable;

/**
 * Writes what spatie/laravel-backup did into a table the foundation can read.
 *
 * The package emails on failure, which is the right default and is not enough:
 * an email is read once by whoever happened to open it, and a backup that has
 * been failing for three weeks looks exactly like one nobody is emailing about.
 * A table with a last-successful timestamp answers "are we backed up?" without
 * anybody having to remember.
 *
 * ── The size is recorded from the zip event, not the success event ──────────
 *
 * `BackupWasSuccessful` carries only a disk name and a backup name.
 * `BackupZipWasCreated` carries the path, which is the only place the size and
 * file count can be measured. They fire in that order for one run, so the zip
 * event stashes what it found and the success event writes the row.
 *
 * File count as well as size, because shared hosting counts INODES — and a
 * media library plus a month of daily archives hits that limit long before it
 * runs out of disk.
 */
class RecordBackupOutcome
{
    /** @var array{path: string, size: int, files: int}|null */
    private static ?array $lastZip = null;

    public function handleZipCreated(BackupZipWasCreated $event): void
    {
        try {
            self::$lastZip = [
                'path' => $event->pathToZip,
                'size' => is_file($event->pathToZip) ? (int) filesize($event->pathToZip) : 0,
                'files' => $this->countEntries($event->pathToZip),
            ];
        } catch (Throwable) {
            // Measuring the archive must never fail the backup that produced it.
            self::$lastZip = null;
        }
    }

    public function handleSuccess(BackupWasSuccessful $event): void
    {
        $entry = BackupLogEntry::create([
            'type' => BackupLogEntry::TYPE_BACKUP,
            'status' => BackupLogEntry::STATUS_COMPLETED,
            'destination' => $event->diskName,
            'filename' => self::$lastZip['path'] ?? null,
            'size_bytes' => self::$lastZip['size'] ?? null,
            'file_count' => self::$lastZip['files'] ?? null,
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        app(AuditLogger::class)->record(
            event: 'backup.completed',
            description: sprintf(
                'Backup written to %s%s.',
                $event->diskName,
                $entry->humanSize() !== null ? ' ('.$entry->humanSize().')' : '',
            ),
            subject: $entry,
        );

        self::$lastZip = null;
    }

    public function handleFailure(BackupHasFailed $event): void
    {
        $entry = BackupLogEntry::create([
            'type' => BackupLogEntry::TYPE_BACKUP,
            'status' => BackupLogEntry::STATUS_FAILED,
            'destination' => $event->diskName,
            'error' => $event->exception->getMessage(),
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        /*
         * Critical, always. A failed backup is not an operational hiccup — it
         * is the foundation being one hardware fault away from losing its donor
         * ledger, its beneficiary records and its receipts.
         */
        app(AuditLogger::class)->record(
            event: 'backup.failed',
            description: 'Backup failed: '.$event->exception->getMessage(),
            subject: $entry,
        );

        self::$lastZip = null;
    }

    /**
     * How many files the archive holds.
     *
     * Opened read-only and closed immediately; a failure here returns zero
     * rather than propagating, because a count is never worth failing a backup
     * over.
     */
    private function countEntries(string $path): int
    {
        if (! class_exists(\ZipArchive::class) || ! is_file($path)) {
            return 0;
        }

        $zip = new \ZipArchive;

        if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
            return 0;
        }

        $count = $zip->numFiles;
        $zip->close();

        return $count;
    }
}
