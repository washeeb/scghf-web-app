<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Communications\Contracts\ReportsDelivery;
use App\Communications\Contracts\SmsGateway;
use App\Models\SmsLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Asks the provider whether the messages it accepted actually arrived.
 *
 * ── Why this exists ─────────────────────────────────────────────────────────
 *
 * An alphanumeric sender ID that is not registered with MTN, Telecel and AT is
 * accepted by mNotify and dropped by the network, with no error returned
 * anywhere. From our side a blocked sender and a working one look identical:
 * every message reports as sent, and nobody receives anything.
 *
 * A run of accepted-but-never-delivered messages is the ONLY evidence that this
 * is happening. So the reports are fetched, and a delivery rate that collapses
 * raises an alert rather than waiting for somebody to say "I never got the text".
 *
 * Nothing here ever infers success. A message with no report stays `sent`,
 * which is the truthful answer — we know we handed it over, and we do not know
 * that it arrived.
 */
class FetchSmsDeliveryReports extends Command
{
    protected $signature = 'scghf:sms-delivery-reports
                            {--limit=200 : How many messages to check}';

    protected $description = 'Ask the SMS provider which accepted messages actually arrived';

    public function handle(SmsGateway $gateway): int
    {
        if (! $gateway instanceof ReportsDelivery) {
            $this->line("The [{$gateway->name()}] driver cannot report delivery. Nothing to do.");

            return self::SUCCESS;
        }

        $window = (int) config('communications.sms.delivery_report_window_hours', 24);

        $pending = SmsLog::query()
            ->where('status', SmsLog::STATUS_SENT)
            ->whereNotNull('provider_message_id')
            // A report that has not appeared within a day is not going to.
            // Asking for ever would poll a growing set of messages nobody will
            // ever learn anything more about.
            ->where('sent_at', '>=', now()->subHours($window))
            ->orderBy('sent_at')
            ->limit((int) $this->option('limit'))
            ->get();

        $delivered = $undelivered = $unknown = 0;

        foreach ($pending as $log) {
            $report = $gateway->deliveryReport((string) $log->provider_message_id);

            if ($report === null) {
                // Still do not know. The row stays `sent`, deliberately.
                $unknown++;

                continue;
            }

            if ($report['status'] === 'delivered') {
                $log->markDelivered($report['detail']);
                $delivered++;

                continue;
            }

            $log->markUndelivered($report['detail'] ?? 'undelivered');
            $undelivered++;
        }

        $this->line(sprintf(
            'Checked %d · delivered %d · undelivered %d · no report yet %d',
            $pending->count(), $delivered, $undelivered, $unknown,
        ));

        return $this->warnIfDeliveryHasCollapsed();
    }

    /**
     * Alert when the delivery rate falls off a cliff.
     *
     * The failure mode being watched for is a sender ID that stops being
     * accepted by the networks — which happens without notice, for instance
     * when a registration lapses. It looks like a sudden, total collapse in
     * delivery while sending continues to succeed.
     *
     * Only assessed once there is enough recent traffic to mean anything: three
     * undelivered messages out of four is noise, not a signal.
     */
    private function warnIfDeliveryHasCollapsed(): int
    {
        $since = now()->subHours((int) config('communications.sms.delivery_report_window_hours', 24));

        $reported = SmsLog::query()
            ->whereIn('status', [SmsLog::STATUS_DELIVERED, SmsLog::STATUS_UNDELIVERED])
            ->where('sent_at', '>=', $since)
            ->count();

        if ($reported < 20) {
            return self::SUCCESS;
        }

        $delivered = SmsLog::query()
            ->where('status', SmsLog::STATUS_DELIVERED)
            ->where('sent_at', '>=', $since)
            ->count();

        $rate = (int) round($delivered / $reported * 100);

        if ($rate >= 60) {
            return self::SUCCESS;
        }

        $message = sprintf(
            'SMS delivery has collapsed to %d%% over the last %d reported messages. The usual '
            .'cause is the sender ID [%s] no longer being accepted by the networks — which '
            .'happens without notice and looks exactly like this: messages accepted, nothing '
            .'delivered. Check the registration with mNotify.',
            $rate,
            $reported,
            (string) config('communications.sms.sender_id'),
        );

        $this->error($message);
        Log::critical($message);

        // Non-zero so cron emails somebody. A report nobody reads is the same
        // as no report.
        return self::FAILURE;
    }
}
