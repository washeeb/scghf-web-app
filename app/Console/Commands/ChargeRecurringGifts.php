<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Payments\RecurringGivingService;
use Illuminate\Console\Command;

/**
 * Charges recurring gifts that fall due.
 *
 * Run from cPanel cron, because InMotion shared hosting has no Supervisor and
 * no persistent process:
 *
 *     0 6 * * * php /home/USER/app/artisan scghf:charge-recurring --execute
 *
 * Once a day, in the morning, so a failure is noticed during working hours
 * rather than at 3am. Safe to run twice: the unique index on
 * (subscription_id, scheduled_on) makes an overlapping run a no-op rather than
 * a double charge.
 *
 * Dry run by default. This takes money from donors' accounts, and a command
 * that does that on a bare invocation is one keystroke from a mistake.
 */
class ChargeRecurringGifts extends Command
{
    protected $signature = 'scghf:charge-recurring
                            {--execute : Actually take the payments}
                            {--on= : Charge as at this date, for catching up}';

    protected $description = 'Charge recurring gifts that are due';

    public function handle(RecurringGivingService $recurring): int
    {
        $on = $this->option('on') ? now()->parse((string) $this->option('on')) : now();
        $execute = (bool) $this->option('execute');

        if (! $execute) {
            $this->warn('DRY RUN — nothing will be charged. Add --execute to take the payments.');
        }

        $summary = $recurring->chargeDue($on, $execute);

        $this->newLine();
        $this->line(sprintf(
            'Due: %d   Charged: %d   Failed: %d   Blocked: %d',
            $summary['due'],
            $summary['charged'],
            $summary['failed'],
            $summary['blocked'],
        ));

        foreach ($summary['detail'] as $line) {
            $this->line('  '.$line);
        }

        if ($summary['blocked'] > 0) {
            $this->newLine();
            /*
             * The Ghana caveat, surfaced where somebody will see it. A donor
             * who set up a monthly gift and whose mobile-money authorization
             * cannot be reused believes they are giving and is not.
             */
            $this->warn(
                $summary['blocked'].' subscription(s) are due but cannot be charged — usually a '
                .'mobile-money authorization that is not reusable. Those donors need to be asked '
                .'to give again, or to move to a card.'
            );
        }

        // A failed charge is not a failed RUN. Exit non-zero only when nothing
        // could be attempted at all, so cron does not email about normal
        // declines every morning.
        return self::SUCCESS;
    }
}
