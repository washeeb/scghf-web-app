<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AuditArchive;
use App\Models\AuditLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Walks the audit chain and reports the first place it breaks.
 *
 * ── What this proves, and what it does not ──────────────────────────────────
 *
 * Each entry hashes its own content together with the previous entry's hash, so
 * editing or removing one breaks every hash after it. This command finds the
 * break and names the entry.
 *
 * It makes tampering DETECTABLE. It does not make it impossible. Somebody with
 * database access can recompute the chain from the tampered point onward and
 * leave it internally consistent — this command would then report a clean
 * chain, truthfully, over a rewritten history.
 *
 * What defeats that is ANCHORING: writing the head hash somewhere the database
 * cannot reach. This command does that on every clean run, to the application
 * log, which on this host is a file on disk and in production should be shipped
 * off the server. An auditor comparing today's head against last month's
 * anchored value is the actual control; the chain is what makes that comparison
 * mean something.
 *
 * Claiming more than this for a hash chain in a database the application can
 * write to would be dishonest, so it is written down here rather than implied.
 */
class VerifyAuditLog extends Command
{
    protected $signature = 'scghf:verify-audit-log
                            {--from= : Only verify entries after this id}
                            {--quiet-when-clean : Say nothing unless something is wrong}';

    protected $description = 'Verify the audit trail has not been altered, and anchor its head';

    public function handle(): int
    {
        $from = $this->option('from') !== null ? (int) $this->option('from') : 0;

        // A --from run deliberately begins part-way along, so the first entry
        // it sees is expected to have a predecessor it will not examine.
        $startsMidChain = $from > 0;

        $previousHash = null;
        $checked = 0;
        $archived = 0;
        $firstBreak = null;

        AuditLog::query()
            ->where('id', '>', $from)
            // By id, not by occurred_at. A backdated entry — a queued job, a
            // webhook processed late — still chains in insertion order, and
            // verifying by timestamp would report clock skew as tampering.
            ->orderBy('id')
            ->chunk(500, function ($entries) use (
                &$previousHash, &$checked, &$archived, &$firstBreak, $startsMidChain
            ): bool {
                foreach ($entries as $entry) {
                    $isFirst = $checked === 0;
                    $checked++;

                    if (! $entry->hashIsIntact()) {
                        $firstBreak = [$entry, 'the entry\'s own content no longer matches its hash — '
                            .'it has been edited'];

                        return false;
                    }

                    /*
                     * What this entry claims came before it, checked against
                     * what actually did.
                     *
                     * The first entry examined is the awkward one. It has
                     * nothing computed to compare against, so it is checked
                     * against the three things that could legitimately precede
                     * it: nothing at all (the genuine start of the chain), a
                     * pruned archive (a documented move), or a --from run that
                     * deliberately began mid-chain.
                     *
                     * Accepting it unconditionally — which is the obvious
                     * implementation — would mean somebody could delete the
                     * OLDEST entries and have verification pass, because
                     * whatever survived would become the new start.
                     */
                    $expected = $isFirst ? $entry->previous_hash : $previousHash;

                    if ($entry->previous_hash !== $expected) {
                        $firstBreak = [$entry, 'the link to the previous entry does not match — '
                            .'an entry has been removed, reordered or inserted, and no archive '
                            .'accounts for the gap'];

                        return false;
                    }

                    if ($isFirst && ! $startsMidChain && $entry->previous_hash !== null) {
                        /*
                         * Something came before this and is no longer here. That
                         * is legitimate only if an archive took it — and only if
                         * the archive was actually PRUNED. An archive written
                         * but not pruned means the rows should still be present,
                         * so their absence is a deletion by some other hand.
                         */
                        $archive = AuditArchive::endingWith((string) $entry->previous_hash);

                        if ($archive === null || ! $archive->isPruned()) {
                            $firstBreak = [$entry, 'entries before this one are missing and no '
                                .'pruned archive accounts for them'];

                            return false;
                        }

                        $archived++;
                    }

                    $previousHash = $entry->hash;
                }

                return true;
            });

        if ($firstBreak !== null) {
            return $this->reportBreak($firstBreak[0], $firstBreak[1], $checked);
        }

        return $this->reportClean($checked, $previousHash, $archived);
    }

    private function reportBreak(AuditLog $entry, string $reason, int $checked): int
    {
        $message = sprintf(
            'AUDIT TRAIL BROKEN at entry %s (id %d, %s): %s.',
            $entry->ulid,
            $entry->id,
            $entry->occurred_at?->format('j M Y H:i') ?? 'unknown time',
            $reason,
        );

        $this->error($message);
        $this->newLine();
        $this->line('  Event:       '.$entry->event);
        $this->line('  Description: '.$entry->description);
        $this->line('  Entries verified before the break: '.($checked - 1));
        $this->newLine();
        $this->warn(
            'Treat this as a security incident until proven otherwise. The trail before this '
            .'point is still verifiable; everything after it is not.'
        );

        // Off-database, because if the database is the problem it is the wrong
        // place to record that it is.
        Log::critical($message, ['audit_log_id' => $entry->id, 'ulid' => $entry->ulid]);

        return self::FAILURE;
    }

    private function reportClean(int $checked, ?string $head, int $archived = 0): int
    {
        if ($checked === 0) {
            if (! $this->option('quiet-when-clean')) {
                $this->line('No audit entries to verify.');
            }

            return self::SUCCESS;
        }

        /*
         * The anchor. Written on every clean run so there is a series of
         * timestamped head values outside the database to compare against.
         * Without it the chain only proves internal consistency, which somebody
         * who rewrote the whole chain would also have.
         */
        Log::info('Audit trail verified.', [
            'entries' => $checked,
            'head_hash' => $head,
            'verified_at' => now()->toIso8601String(),
        ]);

        if (! $this->option('quiet-when-clean')) {
            $this->info("Audit trail intact — {$checked} entries verified.");

            if ($archived > 0) {
                // Said out loud, because a chain that "jumped" is exactly what
                // somebody reading this needs to know was deliberate.
                $this->line(sprintf(
                    'The chain continues across %d archived %s.',
                    $archived,
                    $archived === 1 ? 'period' : 'periods',
                ));
            }

            $this->line('Head hash anchored to the application log: '.substr((string) $head, 0, 16).'…');
        }

        return self::SUCCESS;
    }
}
