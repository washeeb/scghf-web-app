<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\RecordsAuthor;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * A closed year of the audit trail, moved out of the live table.
 *
 * ── The problem this solves ─────────────────────────────────────────────────
 *
 * `audit_logs` is never swept by the retention runner, and that is correct: it
 * is the evidence that the retention policy was followed, and a policy which
 * destroys its own evidence cannot be demonstrated to anybody.
 *
 * The consequence is that on a shared host it becomes the largest table in the
 * database. Every nightly backup drags years of it across a slow connection,
 * every restore takes longer, and the inode and disk quotas notice. Deleting it
 * is not an option. Moving it is.
 *
 * ── Why this is safe, when deleting rows normally is not ────────────────────
 *
 * The hash chain is what makes the difference. Each archive records the first
 * and last hashes of the block it took, so:
 *
 *   - the entries that remain still chain onto `last_entry_hash`, and the
 *     verifier is told to expect that rather than reporting a break
 *   - a restored file can be checked against `archive_hash`, so "we have the
 *     archive" is a verifiable statement rather than a claim about a file
 *     nobody has opened
 *   - a gap with no archive row is still a break, which is exactly what should
 *     happen if somebody deletes a year by hand
 *
 * ── Write first, prune second ───────────────────────────────────────────────
 *
 * The file is written and its hash verified BEFORE a single row is removed. A
 * crash in between leaves an archive that has not been pruned — harmless, and
 * re-runnable. The other order loses a year of evidence to a failed write.
 */
class AuditArchive extends Model
{
    use HasFactory;
    use HasUlids;
    use RecordsAuthor;

    protected $fillable = [
        'year', 'entry_count', 'first_entry_id', 'last_entry_id',
        'period_start', 'period_end', 'first_entry_hash', 'last_entry_hash',
        'archive_hash', 'filename', 'disk', 'size_bytes', 'created_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'disk' => 'local',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'entry_count' => 'integer',
            'first_entry_id' => 'integer',
            'last_entry_id' => 'integer',
            'size_bytes' => 'integer',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'pruned_at' => 'datetime',
            'verified_at' => 'datetime',
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

    /**
     * Whether the archive file is still there and still what it was.
     *
     * Re-hashes the file rather than trusting that it exists. A backup that
     * silently truncated, a disk that filled mid-write, a file somebody moved —
     * all of them leave a file present and wrong, which is worse than one
     * missing, because the missing one gets noticed.
     */
    public function verify(): bool
    {
        $disk = Storage::disk($this->disk);

        if (! $disk->exists($this->filename)) {
            return false;
        }

        $intact = hash_equals(
            $this->archive_hash,
            hash('sha256', (string) $disk->get($this->filename)),
        );

        if ($intact) {
            $this->forceFill(['verified_at' => now()])->save();
        }

        return $intact;
    }

    public function markPruned(): void
    {
        $this->forceFill(['pruned_at' => now()])->save();
    }

    public function isPruned(): bool
    {
        return $this->pruned_at !== null;
    }

    /**
     * The archive covering a given entry id, if that id has been moved out.
     *
     * This is what turns a gap in the live table from evidence of tampering
     * into a documented, checkable move.
     */
    public static function covering(int $entryId): ?self
    {
        return static::query()
            ->where('first_entry_id', '<=', $entryId)
            ->where('last_entry_id', '>=', $entryId)
            ->first();
    }

    /**
     * The archive whose block ends immediately before a surviving entry.
     *
     * The verifier uses this: an entry whose `previous_hash` matches an
     * archive's `last_entry_hash` is chained correctly across the gap, not
     * broken by it.
     */
    public static function endingWith(string $hash): ?self
    {
        return static::query()->where('last_entry_hash', $hash)->first();
    }

    public function humanSize(): string
    {
        $bytes = (int) $this->size_bytes;
        $units = ['B', 'KB', 'MB', 'GB'];
        $power = $bytes > 0 ? min((int) floor(log($bytes, 1024)), count($units) - 1) : 0;

        return round($bytes / (1024 ** $power), 1).' '.$units[$power];
    }

    public function summary(): string
    {
        return sprintf(
            '%d — %s entries, %s, %s%s',
            $this->year,
            number_format($this->entry_count),
            $this->humanSize(),
            $this->isPruned() ? 'removed from the live table' : 'still in the live table',
            $this->verified_at !== null
                ? ', verified '.$this->verified_at->format('j M Y')
                : ', never verified',
        );
    }

    /** Archives written but whose rows are still occupying the live table. */
    #[Scope]
    protected function unpruned(Builder $query): void
    {
        $query->whereNull('pruned_at');
    }
}
