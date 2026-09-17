<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * A backup run — or, more usefully, a restore test.
 *
 * ── The reason this table is not just a log ─────────────────────────────────
 *
 * **A backup that has never been restored is a hypothesis.**
 *
 * `CLAUDE.md` lists it among the things commonly forgotten, and it is the one
 * thing this table exists to make hard to forget. A year of green ticks proves
 * an archive was written. It proves nothing about whether the foundation could
 * get its data back, which is the only question that matters on the day it
 * matters — and the usual answer, discovered at the worst possible moment, is
 * that the archive was of an empty database, or encrypted with a key nobody
 * kept, or missing the media directory.
 *
 * So `restore_test` is a first-class row type, `restoreTestIsOverdue()` is
 * loud, and a restore test that counted nothing and was signed by nobody is
 * refused — because a note saying "seemed fine" is not evidence.
 *
 * ── Inodes, not just bytes ──────────────────────────────────────────────────
 *
 * `file_count` is recorded alongside `size_bytes` because shared hosting counts
 * inodes, and a media library plus a month of daily archives hits that limit
 * long before it runs out of disk. "1.2GB across 180,000 files" is two
 * different warnings.
 */
class BackupLogEntry extends Model
{
    use HasFactory;
    use HasUlids;

    protected $table = 'backups_log';

    public const TYPE_BACKUP = 'backup';

    public const TYPE_CLEANUP = 'cleanup';

    public const TYPE_RESTORE_TEST = 'restore_test';

    public const STATUS_STARTED = 'started';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'type', 'status', 'destination', 'filename',
        'size_bytes', 'file_count', 'duration_seconds', 'error',
        'source_backup_id', 'restored_row_count', 'restore_notes', 'verified_by',
        'started_at', 'finished_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'type' => self::TYPE_BACKUP,
        'status' => self::STATUS_STARTED,
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'file_count' => 'integer',
            'duration_seconds' => 'integer',
            'restored_row_count' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return array<int, string> */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return BelongsTo<User, $this> */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /** @return BelongsTo<self, $this> */
    public function sourceBackup(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_backup_id');
    }

    // ── Recording ────────────────────────────────────────────────────────────

    public static function begin(string $type, ?string $destination = null): self
    {
        return static::create([
            'type' => $type,
            'destination' => $destination,
            'started_at' => now(),
        ]);
    }

    public function complete(?int $sizeBytes = null, ?int $fileCount = null, ?string $filename = null): void
    {
        $this->forceFill([
            'status' => self::STATUS_COMPLETED,
            'size_bytes' => $sizeBytes,
            'file_count' => $fileCount,
            'filename' => $filename,
            'finished_at' => now(),
            'duration_seconds' => (int) $this->started_at->diffInSeconds(now()),
        ])->save();
    }

    public function fail(string $error): void
    {
        $this->forceFill([
            'status' => self::STATUS_FAILED,
            'error' => $error,
            'finished_at' => now(),
        ])->save();
    }

    /**
     * Record that somebody actually restored a backup and checked it.
     *
     * Both arguments are required, and that is the point. A restore test with
     * no row count did not verify anything, and one with no named verifier is
     * an assertion rather than evidence. Refusing here is what stops this
     * becoming a checkbox somebody ticks quarterly.
     */
    public static function recordRestoreTest(
        self $sourceBackup,
        User $verifier,
        int $restoredRowCount,
        string $notes,
    ): self {
        if ($restoredRowCount < 1) {
            throw new InvalidArgumentException(
                'A restore test has to count what came back. A restore that produced no rows '
                .'is a failed restore, and recording it as a pass is worse than not testing.'
            );
        }

        if (trim($notes) === '') {
            throw new InvalidArgumentException(
                'A restore test needs notes: what was restored, where, and what was checked. '
                .'"Seemed fine" is not evidence anybody can rely on in a year.'
            );
        }

        return static::create([
            'type' => self::TYPE_RESTORE_TEST,
            'status' => self::STATUS_COMPLETED,
            'source_backup_id' => $sourceBackup->getKey(),
            'verified_by' => $verifier->getKey(),
            'restored_row_count' => $restoredRowCount,
            'restore_notes' => $notes,
            'started_at' => now(),
            'finished_at' => now(),
        ]);
    }

    // ── Health ───────────────────────────────────────────────────────────────

    public static function lastSuccessfulBackup(): ?self
    {
        return static::query()
            ->where('type', self::TYPE_BACKUP)
            ->where('status', self::STATUS_COMPLETED)
            ->latest('started_at')
            ->first();
    }

    public static function lastRestoreTest(): ?self
    {
        return static::query()
            ->where('type', self::TYPE_RESTORE_TEST)
            ->where('status', self::STATUS_COMPLETED)
            ->latest('started_at')
            ->first();
    }

    /**
     * True when nobody has proved a restore works recently enough.
     *
     * True when there has NEVER been one, which is the state every project is
     * in until somebody does it — and the state most projects stay in.
     */
    public static function restoreTestIsOverdue(): bool
    {
        $interval = (int) config('system.backups.restore_test_interval_days', 90);
        $last = static::lastRestoreTest();

        return $last === null || $last->started_at->lt(now()->subDays($interval));
    }

    public static function backupIsStale(): bool
    {
        $hours = (int) config('system.backups.stale_after_hours', 36);
        $last = static::lastSuccessfulBackup();

        return $last === null || $last->started_at->lt(now()->subHours($hours));
    }

    /**
     * Everything wrong with the backup position right now, in sentences.
     *
     * Sentences rather than flags because this goes on a dashboard read by
     * trustees, and "restore_test_overdue: true" tells them nothing about what
     * to do next.
     *
     * @return array<int, string>
     */
    public static function healthWarnings(): array
    {
        $warnings = [];

        if (static::backupIsStale()) {
            $last = static::lastSuccessfulBackup();

            $warnings[] = $last === null
                ? 'No backup has ever completed successfully.'
                : 'The last successful backup was '.$last->started_at->diffForHumans().'.';
        }

        if (static::restoreTestIsOverdue()) {
            $last = static::lastRestoreTest();

            $warnings[] = $last === null
                ? 'No backup has ever been restored and verified. Until one has, the backups are '
                    .'a hypothesis rather than a plan.'
                : 'The last verified restore was '.$last->started_at->diffForHumans()
                    .'. Backups that have not been restored recently are backups nobody has '
                    .'proved still work.';
        }

        $size = static::lastSuccessfulBackup()?->size_bytes;
        $ceiling = (int) config('system.backups.warn_above_bytes');

        if ($size !== null && $ceiling > 0 && $size > $ceiling) {
            $warnings[] = sprintf(
                'The last backup was %s. Shared hosting counts inodes as well as bytes, so check '
                .'the account quota before it is enforced for you.',
                self::formatBytes($size),
            );
        }

        return $warnings;
    }

    public function humanSize(): ?string
    {
        return $this->size_bytes === null ? null : self::formatBytes($this->size_bytes);
    }

    public function nextRestoreTestDue(): ?Carbon
    {
        $last = static::lastRestoreTest();

        return $last?->started_at->copy()
            ->addDays((int) config('system.backups.restore_test_interval_days', 90));
    }

    private static function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = $bytes > 0 ? (int) floor(log($bytes, 1024)) : 0;
        $power = min($power, count($units) - 1);

        return round($bytes / (1024 ** $power), 1).' '.$units[$power];
    }

    #[Scope]
    protected function restoreTests(Builder $query): void
    {
        $query->where('type', self::TYPE_RESTORE_TEST);
    }

    #[Scope]
    protected function failures(Builder $query): void
    {
        $query->where('status', self::STATUS_FAILED);
    }
}
