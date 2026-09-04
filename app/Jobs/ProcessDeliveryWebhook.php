<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Communications\DeliveryEventProcessor;
use App\Models\InboundWebhookEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

/**
 * Acts on a stored delivery event, off the request cycle.
 *
 * Takes the id rather than the model, like ProcessPaymentWebhook: the queue
 * here runs from cron a minute later, and a serialised model is a snapshot of
 * a row that may have moved on.
 */
class ProcessDeliveryWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public array $backoff = [10, 30, 120, 300];

    public function __construct(public readonly int $eventId) {}

    /**
     * One worker per event.
     *
     * Processing is idempotent, but two workers on the same bounce would both
     * call Suppression::record() and race on the lockForUpdate inside it.
     * Cheaper to serialise them than to contend.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('delivery:'.$this->eventId))->releaseAfter(30)->expireAfter(180)];
    }

    public function handle(DeliveryEventProcessor $processor): void
    {
        $event = InboundWebhookEvent::find($this->eventId);

        if ($event === null || $event->isProcessed()) {
            return;
        }

        if ($event->attempts >= $this->tries) {
            /*
             * Stop retrying and leave it for a person. A genuinely broken event
             * retried for ever fills the log and hides itself; a stuck event
             * with a recorded error is something an administrator can find.
             */
            Log::critical('A delivery webhook has exhausted its retries.', [
                'event' => $event->ulid,
                'provider' => $event->provider,
                'type' => $event->event_type,
                'error' => $event->error,
            ]);

            return;
        }

        $processor->process($event);
    }
}
