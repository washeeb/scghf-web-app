<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BackupLogEntry;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PDO;
use Throwable;
use ZipArchive;

/**
 * A backup that has never been restored is a hypothesis.
 *
 * ── What this does ──────────────────────────────────────────────────────────
 *
 * Takes the newest backup archive from the backup disk, opens it (with
 * the archive password), pulls the database dump out, loads it into a
 * SCRATCH database — never the live one — and counts what came back in
 * the tables that matter, against the live counts. If the counts are
 * sane it records the restore test on the backup log with the name of
 * the person who ran it, which is what the health page and the
 * quarterly reminder look for. `BackupLogEntry::recordRestoreTest()` was
 * written in Phase 3 and, until now, called by nobody.
 *
 * ── The scratch database ────────────────────────────────────────────────────
 *
 * `RESTORE_TEST_DATABASE`, created once in cPanel → MySQL Databases and
 * granted to the same user. Empty is fine; it is dropped and rebuilt
 * from the dump every run. There is no `mysql` binary to shell out to
 * on shared hosting that can be relied on, so the dump is streamed
 * through PDO statement by statement.
 *
 * ── Never on the live database ──────────────────────────────────────────────
 *
 * Refuses if the scratch name equals the live name, and refuses if the
 * scratch name is empty. Restoring over the live database is the one
 * mistake a restore test must not be able to make.
 */
class RestoreTest extends Command
{
    protected $signature = 'scghf:restore-test
                            {--verified-by= : Email of the staff member running the test}
                            {--notes= : What was checked, for the record}
                            {--keep : Leave the restored data in the scratch database afterwards}';

    protected $description = 'Restore the newest backup into the scratch database, count what came back, and record the test';

    /** Tables whose row counts are compared with the live database. */
    private const CHECK_TABLES = ['users', 'donors', 'donations', 'payment_transactions', 'orders', 'pages', 'media', 'audit_logs'];

    public function handle(AuditLogger $audit): int
    {
        $scratch = (string) config('database.restore_test_database', '');
        $live = (string) config('database.connections.'.config('database.default').'.database', '');

        if ($scratch === '' || $scratch === $live) {
            $this->error('RESTORE_TEST_DATABASE must name a separate, empty database. It is '.($scratch === '' ? 'not set' : 'the live database').'.');

            return self::FAILURE;
        }

        $verifier = $this->option('verified-by')
            ? User::query()->where('email', mb_strtolower((string) $this->option('verified-by')))->first()
            : null;

        if ($verifier === null) {
            $this->error('--verified-by must be the email of a staff account. A restore test nobody signed is an assertion, not evidence.');

            return self::FAILURE;
        }

        $backup = BackupLogEntry::query()
            ->where('type', BackupLogEntry::TYPE_BACKUP)
            ->where('status', BackupLogEntry::STATUS_COMPLETED)
            ->whereNotNull('filename')
            ->latest('id')
            ->first();

        if ($backup === null) {
            $this->error('No completed backup with a file on record. Run backup:run first.');

            return self::FAILURE;
        }

        $disk = Storage::disk((string) ($backup->destination ?: config('backup.backup.destination.disks.0', 'backups')));

        if (! $disk->exists((string) $backup->filename)) {
            $this->error(sprintf('The newest backup (%s) is not on the %s disk any more.', $backup->filename, $backup->destination));

            return self::FAILURE;
        }

        $this->info(sprintf('Restoring %s (%s) into %s…', $backup->filename, $backup->humanSize() ?? '?', $scratch));

        $tmp = tempnam(sys_get_temp_dir(), 'scghf-restore-');
        file_put_contents($tmp, $disk->readStream((string) $backup->filename));

        try {
            $sql = $this->extractDump($tmp);
            $statements = $this->loadDump($sql, $scratch);
            $counts = $this->compareCounts($scratch, $live);
        } catch (Throwable $e) {
            $this->error('Restore failed: '.$e->getMessage());
            $audit->record('backup.restore_tested', 'Restore test FAILED: '.$e->getMessage(), $backup, $verifier);

            return self::FAILURE;
        } finally {
            @unlink($tmp);
            @unlink($tmp.'.sql');
        }

        $this->table(['Table', 'Restored', 'Live'], array_map(fn (string $t, array $c): array => [$t, $c['restored'], $c['live']], array_keys($counts), $counts));

        $restoredRows = array_sum(array_column($counts, 'restored'));
        $short = array_filter($counts, fn (array $c): bool => $c['restored'] < (int) floor($c['live'] * 0.9));

        if ($restoredRows < 1) {
            $this->error('The restore produced no rows in the checked tables. Not recorded as a pass.');

            return self::FAILURE;
        }

        if ($short !== []) {
            $this->warn('Tables noticeably short of the live count: '.implode(', ', array_keys($short)).'. Expected only if the backup is older than the day’s activity.');
        }

        $notes = (string) ($this->option('notes') ?: sprintf(
            'Restored %s into %s via scghf:restore-test: %d statements, %d rows across %s.',
            $backup->filename,
            $scratch,
            $statements,
            $restoredRows,
            implode(', ', array_keys($counts)),
        ));

        BackupLogEntry::recordRestoreTest($backup, $verifier, $restoredRows, $notes);
        $audit->record('backup.restore_tested', $notes, $backup, $verifier, ['counts' => $counts]);

        if (! $this->option('keep')) {
            $this->wipe($scratch);
        }

        $this->info('Restore test recorded. The health page will stop asking for ninety days.');

        return self::SUCCESS;
    }

    /** The SQL dump out of spatie's zip, to a file beside the archive. */
    private function extractDump(string $zipPath): string
    {
        $zip = new ZipArchive;

        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException('The archive could not be opened.');
        }

        $password = (string) config('backup.backup.password', '');

        if ($password !== '') {
            $zip->setPassword($password);
        }

        $entry = null;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);

            if (str_starts_with($name, 'db-dumps/') && str_ends_with($name, '.sql')) {
                $entry = $name;

                break;
            }
        }

        if ($entry === null) {
            throw new \RuntimeException('No database dump (db-dumps/*.sql) in the archive.');
        }

        $stream = $zip->getStream($entry);

        if ($stream === false) {
            throw new \RuntimeException('The dump could not be read — wrong BACKUP_ARCHIVE_PASSWORD?');
        }

        $out = $zipPath.'.sql';
        file_put_contents($out, $stream);
        fclose($stream);
        $zip->close();

        return $out;
    }

    /** Stream the dump into the scratch database, one statement at a time. */
    private function loadDump(string $sqlPath, string $scratch): int
    {
        $this->wipe($scratch);

        $pdo = $this->scratchPdo($scratch);
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');

        $handle = fopen($sqlPath, 'rb');
        $buffer = '';
        $count = 0;

        while (($line = fgets($handle)) !== false) {
            $trimmed = ltrim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '--') || str_starts_with($trimmed, '/*!') && str_ends_with(rtrim($trimmed), '*/;')) {
                continue;
            }

            $buffer .= $line;

            if (str_ends_with(rtrim($line), ';')) {
                $pdo->exec($buffer);
                $buffer = '';
                $count++;
            }
        }

        fclose($handle);
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

        return $count;
    }

    /** @return array<string, array{restored: int, live: int}> */
    private function compareCounts(string $scratch, string $live): array
    {
        $pdo = $this->scratchPdo($scratch);
        $counts = [];

        foreach (self::CHECK_TABLES as $table) {
            $exists = $pdo->query("SHOW TABLES LIKE '{$table}'")->fetchColumn() !== false;
            $counts[$table] = [
                'restored' => $exists ? (int) $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn() : 0,
                'live' => (int) DB::table($table)->count(),
            ];
        }

        return $counts;
    }

    private function wipe(string $scratch): void
    {
        $pdo = $this->scratchPdo($scratch);
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');

        foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    private function scratchPdo(string $scratch): PDO
    {
        // The live connection, whichever flavour it is: `mysql` locally and in CI,
        // `mariadb` on the InMotion server (MariaDB 10.6). Same PDO driver either way.
        $c = (array) config('database.connections.'.config('database.default'));

        return new PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $c['host'] ?? '127.0.0.1', $c['port'] ?? 3306, $scratch),
            (string) ($c['username'] ?? ''),
            (string) ($c['password'] ?? ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }
}
