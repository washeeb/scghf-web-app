<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Communications\MessageDispatcher;
use App\Communications\SendThrottle;
use App\Models\ScheduledMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * Drains the outbox, a batch at a time, inside the host's sending limits.
 *
 * Runs every minute from the scheduler:
 *
 *     * * * * * php /home/USER/app/artisan schedule:run
 *
 * ── Why this is a command and not a queued job ──────────────────────────────
 *
 * InMotion shared hosting caps outbound mail per hour, so sending a newsletter
 * to two thousand people takes most of a day. That is not a queue backlog to be
 * cleared faster; it is the shape of the problem. A command that sends what it
 * is allowed to send and stops is honest about that, and leaves the remainder
 * visible in a table somebody can look at, cancel from, or reprioritise.
 *
 * ── Not `--execute`-gated, unlike the money commands ────────────────────────
 *
 * `scghf:charge-recurring` and the retention sweep both default to a dry run
 * because they move money and destroy records. This one sends messages that
 * something already decided to send — the decision was made when the row was
 * created. Requiring a flag would mean receipts sitting in the outbox until
 * somebody remembered.
 */
class SendScheduledMessages extends Command
{
    protected $signature = 'scghf:send-messages
                            {--channel= : email or sms only}
                            {--limit= : Override the batch size}
                            {--dry-run : Show what would be sent, send nothing}';

    protected $description = 'Send due messages from the outbox, within the host sending limits';

    public function handle(MessageDispatcher $dispatcher, SendThrottle $throttle): int
    {
        $channels = $this->option('channel') !== null
            ? [(string) $this->option('channel')]
            : [ScheduledMessage::CHANNEL_EMAIL, ScheduledMessage::CHANNEL_SMS];

        $workerId = 'cron_'.Str::lower(Str::ulid()->toBase32());
        $sent = $failed = $blocked = 0;

        foreach ($channels as $channel) {
            $throttleKey = $channel === ScheduledMessage::CHANNEL_EMAIL ? 'mail' : 'sms';

            $allowance = $this->option('limit') !== null
                ? (int) $this->option('limit')
                : $throttle->batchSize($throttleKey);

            if ($allowance < 1) {
                $this->line("{$channel}: ".$throttle->explain($throttleKey));

                continue;
            }

            if ($this->option('dry-run')) {
                $waiting = ScheduledMessage::pendingCount($channel);
                $this->line("{$channel}: {$waiting} waiting, {$allowance} could go now.");

                continue;
            }

            $batch = ScheduledMessage::claimBatch($workerId, $allowance, $channel);

            foreach ($batch as $message) {
                try {
                    $log = $dispatcher->deliver($message);

                    match ($log->status) {
                        'sent' => $sent++,
                        'suppressed', 'disabled' => $blocked++,
                        default => $failed++,
                    };
                } catch (Throwable $e) {
                    /*
                     * One bad message must not strand the rest of the batch —
                     * they are already claimed, so aborting here would leave
                     * them unsendable for the whole claim TTL. `deliver()` has
                     * already recorded the failure against the row.
                     */
                    $failed++;
                    $this->warn("  {$message->ulid}: {$e->getMessage()}");
                }
            }
        }

        $this->line(sprintf('Sent: %d   Blocked: %d   Failed: %d', $sent, $blocked, $failed));

        $oldest = ScheduledMessage::oldestPending();

        if ($oldest !== null && $oldest->diffInHours(now()) >= 6) {
            /*
             * The number that actually matters on this host is not the queue
             * length, it is how long the oldest thing has been waiting. Six
             * hours means either the limits are set too low for the volume or
             * something has stopped draining.
             */
            $this->warn(sprintf(
                'Oldest message has been waiting since %s. Either the hourly limit is too low '
                .'for the volume being queued, or the scheduler is not running.',
                $oldest->format('j M H:i'),
            ));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
