<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AuditArchive;
use App\Models\AuditLog;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
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

        $entries = AuditLog::query()
            ->whereYear('occurred_at', $year)
            ->orderBy('id')
            ->get();

        if ($entries->isEmpty()) {
            $this->line("No entries for {$year}.");

            return self::SUCCESS;
        }

        $this->line(sprintf('%s entries in %d.', number_format($entries->count()), $year));

        if (! $this->verifyBlock($entries)) {
            return self::FAILURE;
        }

        if (! $this->option('execute')) {
            $this->warn('DRY RUN — nothing written, nothing removed. Add --execute to archive.');

            return self::SUCCESS;
        }

        $archive = $this->write($year, $entries);

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

        $this->prune($archive, $entries->pluck('id')->all());

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
     * Check the chain over exactly the block being archived.
     *
     * A year that is already broken must not be archived — archiving it would
     * file the break away in a directory nobody opens, and the live table would
     * come back clean.
     *
     * @param  Collection<int, AuditLog>  $entries
     */
    private function verifyBlock($entries): bool
    {
        $previous = null;

        foreach ($entries as $entry) {
            if ($previous !== null && $entry->previous_hash !== $previous) {
                $this->error(
                    "The chain is already broken at entry {$entry->ulid} (id {$entry->id}). "
                    .'Nothing will be archived — archiving a broken year would file the break '
                    .'somewhere nobody looks. Investigate first.'
                );

                return false;
            }

            if (! $entry->hashIsIntact()) {
                $this->error("Entry {$entry->ulid} has been edited. Nothing will be archived.");

                return false;
            }

            $previous = $entry->hash;
        }

        $this->line('Chain verified over the block being archived.');

        return true;
    }

    /**
     * @param  Collection<int, AuditLog>  $entries
     */
    private function write(int $year, $entries): AuditArchive
    {
        $disk = (string) config('system.audit.archive_disk', 'local');
        $filename = "audit-archives/audit-{$year}.json.gz";

        /*
         * The full row, not a summary. An archive that dropped columns to save
         * space would be an archive somebody could not verify against the
         * hashes — the hash is taken over every field.
         */
        $payload = json_encode([
            'year' => $year,
            'exported_at' => now()->toIso8601String(),
            'entry_count' => $entries->count(),
            'entries' => $entries->map(fn (AuditLog $e): array => $e->getAttributes())->all(),
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        $compressed = gzencode($payload, 9);

        if ($compressed === false) {
            throw new RuntimeException('The archive could not be compressed.');
        }

        Storage::disk($disk)->put($filename, $compressed);

        $first = $entries->first();
        $last = $entries->last();

        return AuditArchive::create([
            'year' => $year,
            'entry_count' => $entries->count(),
            'first_entry_id' => $first->id,
            'last_entry_id' => $last->id,
            'period_start' => $first->occurred_at,
            'period_end' => $last->occurred_at,
            // What the block started from, and what the entries after the gap
            // will chain onto.
            'first_entry_hash' => (string) $first->hash,
            'last_entry_hash' => (string) $last->hash,
            'archive_hash' => hash('sha256', $compressed),
            'filename' => $filename,
            'disk' => $disk,
            'size_bytes' => strlen($compressed),
        ]);
    }

    /**
     * Remove the archived rows.
     *
     * `AuditLog` refuses deletion through the model — correctly, since that
     * guard is what stops somebody tidying away an inconvenient entry. This
     * goes round it deliberately and only here, in a transaction, after the
     * archive has been written AND verified, and it records the archive first
     * so the gap is explained before it exists.
     *
     * @param  array<int, int>  $ids
     */
    private function prune(AuditArchive $archive, array $ids): void
    {
        DB::transaction(function () use ($archive, $ids): void {
            foreach (array_chunk($ids, 500) as $chunk) {
                DB::table('audit_logs')->whereIn('id', $chunk)->delete();
            }

            $archive->markPruned();
        });
    }
}
