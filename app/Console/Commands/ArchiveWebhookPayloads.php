<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Move old webhook bodies out of the database and into compressed files.
 *
 *     php artisan scghf:archive-webhook-payloads --execute
 *
 * A webhook event is never deleted: the row is the audit trail for every
 * payment the foundation has taken and every bounce its mail provider
 * reported. But the raw body — several kilobytes of JSON per event — is
 * only ever read again for a replay, and a replay of a year-old, processed
 * event is not something anybody does. Kept in the table, those bodies are
 * the second-largest thing the nightly backup drags across a slow
 * connection.
 *
 * So, once an event is processed and older than the cut-off, its body goes
 * into one gzipped JSON-lines file per month, the row keeps everything else
 * plus the body's SHA-256 and the archive's name, and the body column is
 * nulled. The hash is what proves the archived body is the one received.
 * Replay is refused for an archived event (the button is not shown), which
 * is the right answer for an event that old.
 */
class ArchiveWebhookPayloads extends Command
{
    protected $signature = 'scghf:archive-webhook-payloads
                            {--months=12 : Archive bodies of processed events older than this}
                            {--execute : Actually write the archives and null the columns}';

    protected $description = 'Move the raw bodies of old, processed webhook events into monthly compressed files';

    private const TABLES = ['payment_webhook_events', 'inbound_webhook_events'];

    public function handle(): int
    {
        $cutoff = now()->subMonths(max(1, (int) $this->option('months')));
        $execute = (bool) $this->option('execute');
        $disk = (string) config('system.audit.archive_disk', 'local');
        $total = 0;

        foreach (self::TABLES as $table) {
            $count = $this->eligible($table, $cutoff)->count();
            $total += $count;

            $this->line(sprintf('%-26s %s bodies older than %s', $table, number_format($count), $cutoff->toDateString()));

            if ($count === 0 || ! $execute) {
                continue;
            }

            $this->archiveTable($table, $cutoff, $disk);
        }

        if (! $execute && $total > 0) {
            $this->warn('DRY RUN — nothing written, nothing changed. Add --execute to archive.');
        }

        return self::SUCCESS;
    }

    private function eligible(string $table, Carbon $cutoff): Builder
    {
        return DB::table($table)
            ->whereNull('payload_archived_at')
            ->whereNotNull('raw_payload')
            ->whereNotNull('processed_at')
            ->where('received_at', '<', $cutoff);
    }

    private function archiveTable(string $table, Carbon $cutoff, string $disk): void
    {
        $months = $this->eligible($table, $cutoff)
            ->selectRaw("DATE_FORMAT(received_at, '%Y-%m') as month")
            ->distinct()
            ->orderBy('month')
            ->pluck('month');

        foreach ($months as $month) {
            $filename = "webhook-archives/{$table}-{$month}.jsonl.gz";
            $temp = tempnam(sys_get_temp_dir(), 'webhook-archive-');
            $stream = $temp === false ? false : gzopen($temp, 'wb9');

            if ($temp === false || $stream === false) {
                throw new RuntimeException('The archive could not be opened for writing.');
            }

            // An archive for this month may already exist from an earlier run
            // that caught fewer rows (a late-processed event): carry its lines
            // over, then append. One gzip member, so any reader can open it.
            if (Storage::disk($disk)->exists($filename)) {
                gzwrite($stream, (string) gzdecode((string) Storage::disk($disk)->get($filename)));
            }

            $ids = [];
            $hashes = [];

            $this->eligible($table, $cutoff)
                ->whereRaw("DATE_FORMAT(received_at, '%Y-%m') = ?", [$month])
                ->orderBy('id')
                ->chunkById(200, function ($rows) use ($stream, &$ids, &$hashes): void {
                    foreach ($rows as $row) {
                        $body = (string) $row->raw_payload;
                        gzwrite($stream, json_encode([
                            'id' => $row->id,
                            'event_id' => $row->event_id ?? null,
                            'received_at' => $row->received_at,
                            'sha256' => hash('sha256', $body),
                            'raw_payload' => $body,
                        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n");
                        $ids[] = $row->id;
                        $hashes[$row->id] = hash('sha256', $body);
                    }
                });

            gzclose($stream);

            $handle = fopen($temp, 'rb');
            Storage::disk($disk)->put($filename, $handle);
            fclose($handle);
            @unlink($temp);

            // The bodies are nulled only once the file is on the disk: a
            // failure above leaves the table untouched and the run repeatable.
            DB::transaction(function () use ($table, $ids, $hashes, $filename): void {
                foreach (array_chunk($ids, 200) as $chunk) {
                    foreach ($chunk as $id) {
                        DB::table($table)->where('id', $id)->update([
                            'raw_payload' => null,
                            'payload_hash' => $hashes[$id],
                            'payload_archive' => $filename,
                            'payload_archived_at' => now(),
                        ]);
                    }
                }
            });

            $this->info(sprintf('  %s: %s bodies → %s', $month, number_format(count($ids)), $filename));
        }
    }
}
