<?php

declare(strict_types=1);

namespace App\Communications;

use App\Models\EmailLog;
use App\Models\SmsLog;

/**
 * How many more messages may go out right now.
 *
 * InMotion shared hosting caps outbound mail per hour. Exceeding the cap does
 * not queue — it rejects, and repeated rejections get the account flagged. So
 * the sender throttles itself rather than discovering the limit.
 *
 * ── The counter is not a counter ────────────────────────────────────────────
 *
 * It is a COUNT of rows in the log with `sent_at` inside the window.
 *
 * That matters here more than it would elsewhere. The queue runs from cron in
 * fifty-five-second bursts, so every minute is a fresh PHP process with no
 * memory of the last one, and workers are routinely killed mid-batch. A counter
 * held in the cache, in a table, or in memory would drift away from reality the
 * first time that happened — and the direction it drifts in is the one that
 * gets the hosting account suspended.
 *
 * Counting the log is atomic without a lock, self-correcting after a crash, and
 * cannot disagree with what was actually sent, because it IS what was actually
 * sent. It costs one indexed COUNT per batch, which is why `sent_at` is indexed.
 */
class SendThrottle
{
    /** How many more may be sent in this minute and this hour. */
    public function remaining(string $channel): int
    {
        $limits = $this->limits($channel);

        [$lastMinute, $lastHour] = match ($channel) {
            'mail' => [EmailLog::sentInLastMinute(), EmailLog::sentInLastHour()],
            'sms' => [SmsLog::sentInLastMinute(), SmsLog::sentInLastHour()],
            default => [0, 0],
        };

        return max(0, min(
            $limits['per_minute'] - $lastMinute,
            $limits['per_hour'] - $lastHour,
        ));
    }

    public function allows(string $channel, int $count = 1): bool
    {
        return $this->remaining($channel) >= $count;
    }

    /**
     * The batch size to actually claim: whatever is left of the allowance,
     * capped at the configured batch size.
     *
     * Claiming more than the allowance would mean claiming rows we then cannot
     * send — which locks them out of the next worker's batch for the whole
     * claim TTL, for nothing.
     */
    public function batchSize(string $channel): int
    {
        return min(
            $this->remaining($channel),
            (int) config('communications.scheduling.batch_size', 25),
        );
    }

    /**
     * A sentence for the command's output and for the admin dashboard.
     *
     * Written as an explanation rather than a number because the person reading
     * it is usually asking why the newsletter has not gone out yet.
     */
    public function explain(string $channel): string
    {
        $limits = $this->limits($channel);
        $remaining = $this->remaining($channel);

        if ($remaining > 0) {
            return sprintf('%d of %d per hour still available.', $remaining, $limits['per_hour']);
        }

        return sprintf(
            'Hourly limit of %d reached. Sending resumes as the oldest messages fall out of '
            .'the window — this is the host\'s cap, not a fault.',
            $limits['per_hour'],
        );
    }

    /** @return array{per_minute: int, per_hour: int} */
    private function limits(string $channel): array
    {
        /** @var array{per_minute: int, per_hour: int} $limits */
        $limits = config("communications.throttle.{$channel}", ['per_minute' => 0, 'per_hour' => 0]);

        return $limits;
    }
}
