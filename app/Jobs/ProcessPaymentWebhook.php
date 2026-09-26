<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\PaymentWebhookEvent;
use App\Payments\PaymentManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

/**
 * Acts on a stored webhook, off the request cycle.
 *
 * Takes the event's ID rather than the model. A queued job serialises its
 * payload, and on shared hosting the worker runs from cron a minute later — by
 * which time a serialised model is a snapshot of a row that may have moved on.
 * Re-reading it is one query and always current.
 *
 * The queue on this project is cron-driven (`queue:work --stop-when-empty
 * --max-time=55`), because InMotion shared hosting has no Supervisor and no
 * persistent process. So a job may wait up to a minute before it runs, and
 * everything downstream is written to tolerate that.
 */
class ProcessPaymentWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /**
     * Under the worker's own --timeout (50) and QUEUE_RETRY_AFTER (90): a
     * job that hangs on the gateway is killed here, retried by the next
     * minute's worker, and never running twice at once.
     */
    public int $timeout = 45;

    /**
     * Backoff in seconds, growing.
     *
     * The failure this is retrying is almost always transient — a database
     * timeout, a mail service blip. Retrying immediately five times would just
     * hit the same problem five times in a second.
     */
    public array $backoff = [10, 30, 120, 300];

    public function __construct(public readonly int $eventId) {}

    /**
     * One worker per event.
     *
     * Processing is already idempotent, but two workers on the same event would
     * race on the transaction row, and the loser's write could reopen a
     * settled payment. Cheaper to serialise them.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping((string) $this->eventId))->releaseAfter(30)->expireAfter(180)];
    }

    public function handle(PaymentManager $payments): void
    {
        $event = PaymentWebhookEvent::find($this->eventId);

        if ($event === null) {
            // Webhook events are never deleted, so this means the row was never
            // committed — nothing to do, and nothing to retry.
            return;
        }

        if ($event->isProcessed()) {
            return;
        }

        if ($event->hasExhaustedAttempts()) {
            /*
             * Stop retrying and leave it for a human. Continuing to retry a
             * genuinely broken event fills the log and hides it; a stuck event
             * with a recorded error is something an administrator can find.
             */
            Log::critical('Webhook event has exhausted its retries and needs manual attention.', [
                'event' => $event->id,
                'type' => $event->event_type,
                'reference' => $event->gateway_reference,
                'error' => $event->processing_error,
            ]);

            return;
        }

        $payments->processWebhook($event);
    }
}
