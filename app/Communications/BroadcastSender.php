<?php

declare(strict_types=1);

namespace App\Communications;

use App\Models\SmsBroadcast;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Puts an approved broadcast into the outbox, one row per number.
 *
 * ── The outbox does the rest ────────────────────────────────────────────────
 *
 * Throttling per minute and per hour, quiet hours for a marketing text, a
 * fresh suppression check the instant each message goes, a log row for
 * every outcome — all of that is the outbox's, and a broadcast gets none
 * of it by a different route. The idempotency key is the broadcast and
 * the number, so queueing twice cannot text anybody twice.
 */
final class BroadcastSender
{
    public function __construct(private readonly MessageDispatcher $dispatcher) {}

    /** @return int how many were queued */
    public function queue(SmsBroadcast $broadcast): int
    {
        if (! $broadcast->isApproved()) {
            throw new RuntimeException('This broadcast has not been approved by a second person.');
        }

        if ($broadcast->status !== SmsBroadcast::STATUS_DRAFT) {
            throw new RuntimeException('This broadcast has already been queued or cancelled.');
        }

        $recipients = $broadcast->recipients();

        if ($recipients->isEmpty()) {
            throw new RuntimeException('Nobody is on this list once suppressions are applied.');
        }

        return DB::transaction(function () use ($broadcast, $recipients): int {
            $queued = 0;

            foreach ($recipients as $recipient) {
                $this->dispatcher->queueSms(SmsBroadcast::TEMPLATE_KEY, $recipient['number'], [
                    'message' => $broadcast->body,
                ], [
                    'to_name' => $recipient['name'],
                    'related' => $broadcast,
                    'send_after' => $broadcast->scheduled_for ?? now(),
                    // A text about last week's event is noise next week.
                    'expires_at' => ($broadcast->scheduled_for ?? now())->copy()->addDays(3),
                    'idempotency_key' => 'sms.broadcast:'.$broadcast->ulid.':'.$recipient['number'],
                    'created_by' => $broadcast->created_by,
                ]);

                $queued++;
            }

            $broadcast->forceFill([
                'status' => SmsBroadcast::STATUS_QUEUED,
                'queued_count' => $queued,
                'queued_at' => now(),
            ])->save();

            return $queued;
        });
    }
}
