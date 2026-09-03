<?php

declare(strict_types=1);

namespace App\Communications;

use App\Communications\Contracts\SmsGateway;
use App\Models\SmsLog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The default gateway: costs and records the message, sends nothing.
 *
 * Not a stub. `SMS_DRIVER=log` is the configured default because the Foundation
 * has no SMS provider yet, and this makes that state INFORMATIVE rather than
 * merely inert — every message is segmented, encoded, attributed to a network
 * and costed at the configured rate, so a month of running the site produces a
 * genuine estimate of what SMS will cost before anybody signs a contract.
 *
 * It also surfaces the two things that go wrong on the real networks, before
 * they can go wrong quietly:
 *
 *   - a sender ID longer than 11 characters, which networks reject
 *   - a body forced into UCS-2, which triples the segment count
 */
class LogSmsGateway implements SmsGateway
{
    public function name(): string
    {
        return 'log';
    }

    public function send(SmsLog $log): SmsResult
    {
        Log::channel(config('logging.default'))->info('SMS (not sent — driver is `log`)', [
            'to' => $log->to_number,
            'network' => $log->network,
            'sender_id' => $log->sender_id,
            'template' => $log->template_key,
            'segments' => $log->segments,
            'encoding' => $log->encoding,
            'estimated_cost' => (string) $log->estimatedCost(),
            'body' => $log->body,
        ]);

        return SmsResult::accepted(
            providerMessageId: 'log_'.Str::lower(Str::ulid()->toBase32()),
            providerStatus: 'logged',
            raw: ['driver' => 'log'],
        );
    }

    /**
     * False, and it matters.
     *
     * Nothing here can confirm delivery, so nothing downstream may treat a
     * `sent` row from this gateway as proof that a message arrived.
     */
    public function supportsDeliveryReports(): bool
    {
        return false;
    }
}
