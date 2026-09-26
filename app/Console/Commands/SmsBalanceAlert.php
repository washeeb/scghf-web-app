<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Communications\Contracts\ReportsBalance;
use App\Communications\Contracts\SmsGateway;
use App\Communications\MessageDispatcher;
use App\Models\ScheduledMessage;
use App\Providers\CommunicationServiceProvider;
use Illuminate\Console\Command;
use Throwable;

/**
 * Ask the gateway what is left and say so, once a day, while it is low.
 *
 * Site Health shows the balance to whoever opens it; this is for the days
 * nobody does. One email a day at most — the idempotency key is the date —
 * to the alerts address, and nothing at all when the driver cannot report
 * a balance or the balance is fine.
 */
class SmsBalanceAlert extends Command
{
    protected $signature = 'scghf:sms-balance {--execute : Send the alert rather than only reporting}';

    protected $description = 'Check the SMS account balance and email the alerts address when it is low';

    public function handle(MessageDispatcher $dispatcher): int
    {
        $driver = CommunicationServiceProvider::smsDriver();

        if ($driver === 'log') {
            $this->info('The log driver has no balance.');

            return self::SUCCESS;
        }

        $gateway = app(SmsGateway::class);

        if (! $gateway instanceof ReportsBalance) {
            $this->info(sprintf('%s does not report a balance.', $driver));

            return self::SUCCESS;
        }

        $balance = $gateway->balance();

        if ($balance === null) {
            $this->warn('The balance could not be read. That is not the same as zero — check the key and the provider.');

            return self::FAILURE;
        }

        $threshold = (float) config('communications.sms.low_balance', 50);
        $this->line(sprintf('%s: %s (warning line %s)', $driver, $balance->format(), $threshold));

        if (! $balance->isLow($threshold)) {
            return self::SUCCESS;
        }

        if (! $this->option('execute')) {
            $this->warn('Low. Add --execute to send the alert.');

            return self::SUCCESS;
        }

        $to = (string) (setting('communications.alert_email') ?: setting('contact.email_general', ''));

        if ($to === '' || str_contains($to, '{{')) {
            $this->error('No alerts address is set (Settings → Email & SMS), so there is nobody to tell.');

            return self::FAILURE;
        }

        $key = 'sms.low_credit:'.now()->toDateString();

        if (ScheduledMessage::query()->where('idempotency_key', $key)->exists()) {
            $this->info('Already sent today.');

            return self::SUCCESS;
        }

        try {
            $dispatcher->queueEmail('sms.low_credit', $to, [
                'balance' => $balance->format(),
                'threshold' => (string) $threshold,
                'provider' => $driver,
            ], ['idempotency_key' => $key]);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Alert queued to '.$to.'.');

        return self::SUCCESS;
    }
}
