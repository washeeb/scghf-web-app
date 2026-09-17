<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Payments\AnomalyAlerts;
use Illuminate\Console\Command;

/**
 * The hourly look at whether money is behaving.
 */
class PaymentAnomalies extends Command
{
    protected $signature = 'scghf:payment-anomalies';

    protected $description = 'Email the alerts address about runs of failed payments or refunds';

    public function handle(AnomalyAlerts $alerts): int
    {
        $found = $alerts->sweep();

        $this->info(sprintf(
            '%d failed payment(s) in the last hour (line %d); %d refund(s) approved in the last day (line %d).',
            $found['failed'],
            (int) config('payments.paystack.anomalies.failed_per_hour'),
            $found['refunds'],
            (int) config('payments.paystack.anomalies.refunds_per_day'),
        ));

        return self::SUCCESS;
    }
}
