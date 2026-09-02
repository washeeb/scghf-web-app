<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\LegalHold;
use App\Support\RetentionRunner;
use App\Support\TaxDeductibility;
use Illuminate\Console\Command;

/**
 * Applies the Act 843 retention schedule.
 *
 * Scheduled weekly rather than nightly: retention periods are measured in
 * months, so a daily run buys nothing and multiplies the chances of a bad run
 * doing damage before anyone notices.
 *
 *   php artisan scghf:retention              # dry run, the default
 *   php artisan scghf:retention --execute    # actually destroys data
 */
class ApplyRetentionPolicy extends Command
{
    protected $signature = 'scghf:retention
                            {--execute : Actually delete or de-identify. Without this it only reports.}
                            {--holds : Also list holds that are due for review}';

    protected $description = 'Apply the beneficiary data retention schedule (Act 843)';

    public function handle(RetentionRunner $runner, TaxDeductibility $tax): int
    {
        $execute = (bool) $this->option('execute');

        if ($execute && ! $this->confirmDestruction()) {
            $this->warn('Aborted.');

            return self::FAILURE;
        }

        $this->info($execute
            ? 'Applying retention policy — this DESTROYS data.'
            : 'Retention dry run. Nothing will be changed. Use --execute to act.');

        $summary = $runner->run($execute);

        $this->newLine();
        $this->table(
            ['Run', 'Mode', 'Due', 'Acted', 'Held', 'Failed'],
            [[
                substr($summary['run_id'], 0, 8),
                $summary['dry_run'] ? 'dry run' : 'EXECUTED',
                $summary['due'],
                $summary['acted'],
                $summary['held'],
                $summary['failed'],
            ]],
        );

        foreach ($summary['detail'] as $line) {
            $this->warn($line);
        }

        if ($summary['held'] > 0) {
            $this->comment(sprintf(
                '%d record(s) were retained under a legal hold. That is the hold working as intended.',
                $summary['held'],
            ));
        }

        if ($summary['failed'] > 0) {
            $this->error(sprintf('%d record(s) could not be processed. See the log.', $summary['failed']));
        }

        $this->reportHoldsDueForReview();
        $this->reportTaxApprovalExpiry($tax);

        return $summary['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * A destructive run asks first, unless there is no human to ask.
     *
     * Non-interactive means the scheduler, which is the intended caller — the
     * prompt exists to stop someone running this by hand without meaning to.
     */
    private function confirmDestruction(): bool
    {
        if (! $this->input->isInteractive()) {
            return true;
        }

        return $this->confirm(
            'This permanently deletes or de-identifies beneficiary records. Continue?',
            false,
        );
    }

    private function reportHoldsDueForReview(): void
    {
        $due = LegalHold::needingReview()->get();

        if ($due->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->warn(sprintf('%d legal hold(s) are past their review date:', $due->count()));

        foreach ($due as $hold) {
            $this->line(sprintf(
                '  %s  %s  (review was due %s)',
                $hold->reference,
                $hold->title,
                $hold->review_on->format('j M Y'),
            ));
        }

        // A hold nobody reviews is how records end up kept for ever by
        // accident — which is its own Act 843 failure, in the other direction.
        $this->comment('A hold that is never reviewed keeps data indefinitely. Review or release each one.');
    }

    private function reportTaxApprovalExpiry(TaxDeductibility $tax): void
    {
        $expiring = $tax->expiringSoon();

        if ($expiring->isEmpty()) {
            return;
        }

        $this->newLine();

        foreach ($expiring as $approval) {
            $days = $approval->daysUntilExpiry();

            $this->warn(sprintf(
                'GRA approval %s expires in %d day(s), on %s.',
                $approval->reference,
                $days,
                $approval->expires_on->format('j M Y'),
            ));
        }

        $this->comment(
            'When it lapses, tax-deductibility wording is disabled automatically across the site. '
            .'Renew it before then, or donors will stop being told something that is true.'
        );
    }
}
