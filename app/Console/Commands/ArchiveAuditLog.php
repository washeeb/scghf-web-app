<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AuditArchive;
use App\Models\AuditLog;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Moves a closed year of the audit trail out of the live table.
 *
 *     php artisan scghf:archive-audit-log 2026 --execute
 *
 * ── Why archive rather than delete ──────────────────────────────────────────
 *
 * `audit_logs` is deliberately never swept by the retention runner: it is the
 * evidence that the retention policy was followed, and a policy that destroys
 * its own evidence cannot be demonstrated. Kept for seven years.
 *
 * The consequence on shared hosting is that it becomes the largest table in the
 * database, dragged across a slow connection by every nightly backup. So whole
 * closed years are written to a compressed file and removed from the live
 * table, leaving a row that proves what was moved.
 *
 * ── The order of operations, and why it is not the obvious one ──────────────
 *
 *   1. verify the chain over the year being archived — a year that was already
 *      broken must not be archived, because archiving it would file the break
 *      away where nobody looks
 *   2. write the file
 *   3. re-read the file and check its hash
 *   4. record the archive row
 *   5. ONLY THEN delete the rows
 *
 * A crash anywhere before step 5 leaves an unpruned archive, which is harmless
 * and re-runnable. The obvious order — delete, then write — loses a year of
 * evidence to a failed write, and there is no second copy.
 *
 * ── The chain survives the gap ──────────────────────────────────────────────
 *
 * The archive records the first and last hashes of the block it took, so the
 * entries that remain still chain onto `last_entry_hash`. A gap WITHOUT an
 * archive row is still reported as a break — which is exactly what should
 * happen when somebody deletes a year by hand.
 */
class ArchiveAuditLog extends Command
{
    protected $signature = 'scghf:archive-audit-log
                            {year? : The year to archive; defaults to the oldest closed year}
                            {--execute : Actually write the archive and remove the rows}
                            {--keep-rows : Write and verify the archive but leave the live table alone}';

    protected $description = 'Move a closed year of the audit trail into a verified archive file';

    public function handle(): int
    {
        $year = $this->resolveYear();

        if ($year === null) {
            $this->line('Nothing to archive: no closed year holds any entries.');

            return self::SUCCESS;
        }

        if (AuditArchive::where('year', $year)->exists()) {
            $this->error("{$year} has already been archived.");

            return self::FAILURE;
        }

        $minimum = now()->year - (int) config('system.audit.archive_after_years', 2);

        if ($year > $minimum) {
            $this->error(
                "{$year} is too recent to archive. Entries stay in the live table for "
                .config('system.audit.archive_after_years', 2).' full years, because that is '
                .'the window in which somebody actually searches them.'
            );

            return self::FAILURE;
        }

        $query = AuditLog::query()->whereYear('occurred_at', $year);
        $count = (clone $query)->count();

        if ($count === 0) {
            $this->line("No entries for {$year}.");

            return self::SUCCESS;
        }

        $this->line(sprintf('%s entries in %d.', number_format($count), $year));

        /*
         * One pass, streamed. A year of audit entries is the largest thing
         * this application ever reads, and on shared hosting the worker has
         * a memory limit a full year does not fit under. So the rows come
         * through 500 at a time, the chain is checked as they pass, and — on
         * --execute — each is written straight into the gzip stream. Nothing
         * holds more than one chunk.
         */
        $writer = $this->option('execute') ? $this->openArchive($year, $count) : null;
        $block = $this->walk($query, $writer);

        if ($block === null) {
            $writer?->discard();

            return self::FAILURE;
        }

        $this->line('Chain verified over the block being archived.');

        if ($writer === null) {
            $this->warn('DRY RUN — nothing written, nothing removed. Add --execute to archive.');

            return self::SUCCESS;
        }

        $archive = $writer->finish($block);

        $this->info("Archive written: {$archive->filename} ({$archive->humanSize()}).");

        if (! $archive->verify()) {
            $this->error(
                'The archive file does not match the hash that was just computed for it. '
                .'Nothing has been removed from the live table.'
            );

            return self::FAILURE;
        }

        $this->info('Archive verified against its hash.');

        if ($this->option('keep-rows')) {
            $this->warn('--keep-rows: the entries remain in the live table.');

            return self::SUCCESS;
        }

        $this->prune($archive, $year);

        $this->info(sprintf(
            '%s entries removed from the live table. The chain continues from %s…',
            number_format($archive->entry_count),
            substr($archive->last_entry_hash, 0, 16),
        ));

        return self::SUCCESS;
    }

    /**
     * The oldest year eligible for archiving, if none was named.
     */
    private function resolveYear(): ?int
    {
        $named = $this->argument('year');

        if ($named !== null) {
            return (int) $named;
        }

        $oldest = AuditLog::query()->min('occurred_at');

        if ($oldest === null) {
            return null;
        }

        $year = (int) date('Y', strtotime((string) $oldest));
        $cutoff = now()->year - (int) config('system.audit.archive_after_years', 2);

        return $year <= $cutoff ? $year : null;
    }

    /**
     * Walk the year in order: check the chain as it passes, and hand each
     * entry to the writer if there is one.
     *
     * A year that is already broken must not be archived — archiving it would
     * file the break away in a directory nobody opens, and the live table would
     * come back clean.
     *
     * @param  Builder<AuditLog>  $query
     * @return array{first: AuditLog, last: AuditLog}|null the ends of the block, or null when the chain is broken
     */
    private function walk(Builder $query, ?ArchiveWriter $writer): ?array
    {
        $previous = null;
        $first = null;
        $last = null;

        foreach ($query->orderBy('id')->lazyById(500) as $entry) {
            /** @var AuditLog $entry */
            if ($previous !== null && $entry->previous_hash !== $previous) {
                $this->error(
                    "The chain is already broken at entry {$entry->ulid} (id {$entry->id}). "
                    .'Nothing will be archived — archiving a broken year would file the break '
                    .'somewhere nobody looks. Investigate first.'
                );

                return null;
            }

            if (! $entry->hashIsIntact()) {
                $this->error("Entry {$entry->ulid} has been edited. Nothing will be archived.");

                return null;
            }

            $writer?->write($entry);

            $previous = $entry->hash;
            $first ??= $entry;
            $last = $entry;
        }

        return $first === null || $last === null ? null : ['first' => $first, 'last' => $last];
    }

    private function openArchive(int $year, int $count): ArchiveWriter
    {
        return new ArchiveWriter(
            year: $year,
            count: $count,
            disk: (string) config('system.audit.archive_disk', 'local'),
            filename: "audit-archives/audit-{$year}.json.gz",
        );
    }

    /**
     * Remove the archived rows.
     *
     * `AuditLog` refuses deletion through the model — correctly, since that
     * guard is what stops somebody tidying away an inconvenient entry. This
     * goes round it deliberately and only here, in a transaction, after the
     * archive has been written AND verified, and it records the archive first
     * so the gap is explained before it exists. Deleted in bounded slices so
     * the transaction never holds a whole year.
     */
    private function prune(AuditArchive $archive, int $year): void
    {
        DB::transaction(function () use ($archive, $year): void {
            do {
                $deleted = DB::table('audit_logs')
                    ->whereYear('occurred_at', $year)
                    ->whereBetween('id', [$archive->first_entry_id, $archive->last_entry_id])
                    ->orderBy('id')
                    ->limit(500)
                    ->delete();
            } while ($deleted > 0);

            $archive->markPruned();
        });
    }
}

/**
 * The archive file, written one entry at a time into a gzip stream.
 *
 * The full row, not a summary. An archive that dropped columns to save
 * space would be an archive somebody could not verify against the hashes —
 * the hash is taken over every field. The result is one JSON document,
 * `{"year", "exported_at", "entry_count", "entries": [...]}`, exactly as
 * before; it is only built without ever being held whole.
 */
final class ArchiveWriter
{
    private readonly string $temp;

    /** @var resource|null */
    private $stream;

    private bool $first = true;

    public function __construct(
        private readonly int $year,
        private readonly int $count,
        private readonly string $disk,
        public readonly string $filename,
    ) {
        $temp = tempnam(sys_get_temp_dir(), 'audit-archive-');
        $stream = $temp === false ? false : gzopen($temp, 'wb9');

        if ($temp === false || $stream === false) {
            throw new RuntimeException('The archive could not be opened for writing.');
        }

        $this->temp = $temp;
        $this->stream = $stream;

        gzwrite($this->stream, sprintf(
            '{"year":%d,"exported_at":%s,"entry_count":%d,"entries":[',
            $this->year,
            json_encode(now()->toIso8601String(), JSON_THROW_ON_ERROR),
            $this->count,
        ));
    }

    public function write(AuditLog $entry): void
    {
        gzwrite($this->stream, ($this->first ? '' : ',').json_encode($entry->getAttributes(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $this->first = false;
    }

    /** @param array{first: AuditLog, last: AuditLog} $block */
    public function finish(array $block): AuditArchive
    {
        gzwrite($this->stream, ']}');
        gzclose($this->stream);
        $this->stream = null;

        $handle = fopen($this->temp, 'rb');

        if ($handle === false) {
            throw new RuntimeException('The archive could not be read back.');
        }

        Storage::disk($this->disk)->put($this->filename, $handle);
        fclose($handle);

        $hash = hash_file('sha256', $this->temp);
        $size = filesize($this->temp);
        @unlink($this->temp);

        return AuditArchive::create([
            'year' => $this->year,
            'entry_count' => $this->count,
            'first_entry_id' => $block['first']->id,
            'last_entry_id' => $block['last']->id,
            'period_start' => $block['first']->occurred_at,
            'period_end' => $block['last']->occurred_at,
            // What the block started from, and what the entries after the gap
            // will chain onto.
            'first_entry_hash' => (string) $block['first']->hash,
            'last_entry_hash' => (string) $block['last']->hash,
            'archive_hash' => (string) $hash,
            'filename' => $this->filename,
            'disk' => $this->disk,
            'size_bytes' => (int) $size,
        ]);
    }

    public function discard(): void
    {
        if (is_resource($this->stream)) {
            gzclose($this->stream);
            $this->stream = null;
        }

        @unlink($this->temp);
    }
}
