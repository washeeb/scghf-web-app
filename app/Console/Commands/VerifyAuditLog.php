<?php

declare(strict_types=1);

namespace App\Console\Commands;

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
        $previousHash = null;
        $checked = 0;
        $firstBreak = null;

        AuditLog::query()
            ->where('id', '>', $from)
            // By id, not by occurred_at. A backdated entry — a queued job, a
            // webhook processed late — still chains in insertion order, and
            // verifying by timestamp would report clock skew as tampering.
            ->orderBy('id')
            ->chunk(500, function ($entries) use (&$previousHash, &$checked, &$firstBreak): bool {
                foreach ($entries as $entry) {
                    $checked++;

                    /*
                     * On the first entry examined we adopt whatever it claims
                     * its predecessor was, because a --from run legitimately
                     * starts mid-chain. Every entry after that must match what
                     * we actually computed.
                     */
                    if ($previousHash !== null && $entry->previous_hash !== $previousHash) {
                        $firstBreak = [$entry, 'the link to the previous entry does not match — '
                            .'an entry has been removed, reordered or inserted'];

                        return false;
                    }

                    if (! $entry->hashIsIntact()) {
                        $firstBreak = [$entry, 'the entry\'s own content no longer matches its hash — '
                            .'it has been edited'];

                        return false;
                    }

                    $previousHash = $entry->hash;
                }

                return true;
            });

        if ($firstBreak !== null) {
            return $this->reportBreak($firstBreak[0], $firstBreak[1], $checked);
        }

        return $this->reportClean($checked, $previousHash);
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

    private function reportClean(int $checked, ?string $head): int
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
            $this->line('Head hash anchored to the application log: '.substr((string) $head, 0, 16).'…');
        }

        return self::SUCCESS;
    }
}
