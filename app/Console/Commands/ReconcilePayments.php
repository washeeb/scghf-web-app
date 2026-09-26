<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Payments\ReconciliationService;
use Illuminate\Console\Command;

/**
 * The daily check that the ledger still agrees with Paystack.
 *
 * From cPanel cron:
 *
 *     30 6 * * * php /home/USER/app/artisan scghf:reconcile-payments --execute
 *
 * Unlike the recurring-charge command, this one is safe to run repeatedly and
 * takes no money — it only asks the gateway what it already knows and records
 * the answer. `--execute` still gates it, because "recovered" and "abandoned"
 * both write to the ledger.
 *
 * Exits non-zero when something needs a person. That is what makes cron email
 * the administrator: a report nobody reads is the same as no report.
 */
class ReconcilePayments extends Command
{
    protected $signature = 'scghf:reconcile-payments
                            {--execute : Write the recovered and abandoned states}
                            {--since= : Look back to this date instead of the configured window}
                            {--series= : Also check the receipt series for a financial year}';

    protected $description = 'Reconcile the ledger against the payment gateway';

    public function handle(ReconciliationService $reconciliation): int
    {
        if (! config('payments.reconciliation.enabled', true)) {
            $this->warn('Reconciliation is disabled in config.');

            return self::SUCCESS;
        }

        $execute = (bool) $this->option('execute');
        $since = $this->option('since') ? now()->parse((string) $this->option('since')) : null;

        if (! $execute) {
            $this->warn('DRY RUN — nothing will be written. Add --execute to record the results.');
        }

        $summary = $reconciliation->run($execute, $since);

        $this->newLine();
        $this->line('Window: '.$summary['window']);
        $this->line(sprintf(
            'Checked: %d   Recovered: %d   Abandoned: %d',
            $summary['checked'],
            $summary['recovered'],
            $summary['abandoned'],
        ));
        $this->line(sprintf(
            'Needs review: %d   Unprocessed webhooks: %d   Missing acknowledgements: %d',
            $summary['mismatches'],
            $summary['unprocessed_webhooks'],
            $summary['missing_receipts'],
        ));

        foreach ($summary['detail'] as $line) {
            $this->line('  '.$line);
        }

        if ($this->option('series')) {
            $this->reportSeries($reconciliation, (int) $this->option('series'));
        }

        if ($reconciliation->needsAttention($summary)) {
            $this->newLine();
            $this->error('Reconciliation found items that need a person to look at them.');

            // Non-zero so cron emails somebody. A report nobody reads is the
            // same as no report.
            return self::FAILURE;
        }

        $this->info('Ledger and gateway agree.');

        return self::SUCCESS;
    }

    /**
     * The receipt series for a year, and any gaps in it.
     *
     * An auditor expects every number to be accounted for, so finding a gap
     * here is considerably better than having one found for you.
     */
    private function reportSeries(ReconciliationService $reconciliation, int $year): void
    {
        $series = $reconciliation->receiptSeriesFor($year);

        $this->newLine();
        $this->line(sprintf(
            'Receipt series %d: %d issued, %s → %s',
            $year,
            $series['count'],
            $series['first'] ?? '—',
            $series['last'] ?? '—',
        ));

        if ($series['gaps'] !== []) {
            $this->error('  GAPS in the series: '.implode(', ', $series['gaps']));
        }
    }
}
