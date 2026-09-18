<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The monthly housekeeping a shared-hosting database needs and nobody does.
 *
 *     php artisan scghf:db-maintain --execute
 *
 * Three things, in order, each of which is a support ticket a year from
 * launch if it is not scheduled:
 *
 *   1. Prune what has no retention reason to exist: expired sessions the
 *      lottery missed, visitor statistics older than the reports look back,
 *      failed jobs older than a month (Laravel's own `queue:prune-failed`),
 *      the activity log past its window (spatie's `activitylog:clean`).
 *      Personal data is NOT pruned here — that is the retention runner's
 *      job, with its holds and its log.
 *
 *   2. `OPTIMIZE TABLE` on the tables that churn — sessions, cache, jobs,
 *      the logs, the statistics. InnoDB never gives deleted space back on
 *      its own; on a host that counts disk against a quota, a table that
 *      has been pruned for a year can be mostly holes.
 *
 *   3. Report the ten largest tables, so the person reading the output
 *      knows what to archive next.
 */
class MaintainDatabase extends Command
{
    protected $signature = 'scghf:db-maintain
                            {--execute : Actually prune and optimise; without it, report only}';

    protected $description = 'Prune expired rows, reclaim space from churning tables, and report the largest';

    /** Tables that delete and re-insert constantly, and so fragment. */
    private const CHURNING = [
        'sessions', 'cache', 'cache_locks', 'jobs', 'failed_jobs', 'job_batches',
        'visitor_stats', 'email_logs', 'sms_logs', 'activity_log', 'scheduled_messages',
        'login_histories', 'error_reports',
    ];

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');

        $this->prune($execute);
        $this->optimise($execute);
        $this->report();

        if (! $execute) {
            $this->warn('DRY RUN — nothing pruned, nothing optimised. Add --execute.');
        }

        return self::SUCCESS;
    }

    private function prune(bool $execute): void
    {
        $this->line('Pruning');

        // Sessions past their lifetime. The database driver only collects
        // them by lottery (2 in 100 requests), which a quiet site never wins.
        $lifetime = (int) config('session.lifetime', 120);
        $expired = DB::table('sessions')->where('last_activity', '<', now()->subMinutes($lifetime)->getTimestamp());
        $this->row('expired sessions', $expired->count(), $execute, fn () => $expired->delete());

        // Daily visitor rows beyond what the analytics page can show
        // (this year against last, so 26 months keeps a full comparison).
        $stats = DB::table('visitor_stats')->where('date', '<', now()->subMonths(26)->toDateString());
        $this->row('visitor statistics over 26 months', $stats->count(), $execute, fn () => $stats->delete());

        // Password reset tokens are single-use and expire in an hour; the
        // rows do not.
        $tokens = DB::table('password_reset_tokens')->where('created_at', '<', now()->subDay());
        $this->row('stale password reset tokens', $tokens->count(), $execute, fn () => $tokens->delete());

        if ($execute) {
            $this->callSilently('queue:prune-failed', ['--hours' => 24 * 30]);
            $this->line('  failed jobs older than 30 days pruned');

            if (Schema::hasTable('activity_log')) {
                $this->callSilently('activitylog:clean', ['--days' => (int) config('activitylog.delete_records_older_than_days', 365)]);
                $this->line('  activity log beyond '.(int) config('activitylog.delete_records_older_than_days', 365).' days pruned');
            }
        }
    }

    private function optimise(bool $execute): void
    {
        $this->line('Optimising');

        foreach (self::CHURNING as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            if (! $execute) {
                $this->line("  would optimise {$table}");

                continue;
            }

            try {
                // OPTIMIZE TABLE on InnoDB is an online rebuild; it takes a
                // brief lock at the end. These tables are small by design.
                DB::statement("OPTIMIZE TABLE `{$table}`");
                $this->line("  optimised {$table}");
            } catch (Throwable $e) {
                $this->warn("  {$table}: ".$e->getMessage());
            }
        }
    }

    private function report(): void
    {
        $this->line('Largest tables');

        try {
            $rows = DB::select(
                'SELECT table_name AS name, ROUND((data_length + index_length) / 1024 / 1024, 1) AS mb, table_rows AS rows_estimate '
                .'FROM information_schema.tables WHERE table_schema = ? ORDER BY (data_length + index_length) DESC LIMIT 10',
                [(string) config('database.connections.'.config('database.default').'.database')],
            );

            $this->table(['Table', 'MB', 'Rows (estimate)'], array_map(fn ($r): array => [$r->name, $r->mb, number_format((int) $r->rows_estimate)], $rows));
        } catch (Throwable $e) {
            $this->warn('  information_schema is not readable here: '.$e->getMessage());
        }
    }

    private function row(string $label, int $count, bool $execute, callable $delete): void
    {
        if ($count === 0) {
            $this->line("  {$label}: none");

            return;
        }

        if ($execute) {
            $delete();
            $this->line("  {$label}: ".number_format($count).' removed');
        } else {
            $this->line("  {$label}: ".number_format($count).' would be removed');
        }
    }
}
