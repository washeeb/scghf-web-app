<?php

declare(strict_types=1);

namespace App\Communications;

use App\Communications\Contracts\ReportsDelivery;
use App\Communications\Contracts\SmsGateway;
use App\Models\SmsLog;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * The ONLY class in this application that talks to mNotify.
 *
 * Same stance as PaystackService: nothing else builds an mNotify URL, knows
 * where the API key goes, or reads mNotify's JSON shape. That knowledge would
 * otherwise end up in as many places as there are call sites, and changing
 * provider would become a rewrite instead of one line in a service provider.
 *
 * ── What this gateway is careful about ──────────────────────────────────────
 *
 * **It never infers delivery.** mNotify accepting a message means mNotify
 * accepted it. Whether MTN, Telecel or AT actually delivered it is a separate
 * question with a separate endpoint — and it is the question that matters,
 * because an unregistered or mis-cased sender ID is accepted by the provider
 * and dropped by the network with no error anywhere. So `send()` returns
 * `accepted`, the log says `sent`, and only a delivery report moves it to
 * `delivered`.
 *
 * **It records the credit balance.** SMS credits are pre-paid and running out
 * is silent from our side — messages are simply rejected. mNotify returns the
 * remaining balance on every send, so it is read and warned on rather than
 * discovered when a receipt does not arrive.
 *
 * **It distinguishes rejection from failure.** An invalid number or a rejected
 * sender ID will fail identically on retry, so it is recorded and left alone.
 * A timeout or a 5xx is worth retrying.
 *
 * ── Response shape ──────────────────────────────────────────────────────────
 *
 * mNotify's documented codes are handled below. The parsing is deliberately
 * defensive — every field is read with a fallback and an unrecognised response
 * is treated as a failure to be retried, never as a success. Confirm the
 * current shape against mNotify's documentation before the first live send;
 * the `log` driver remains the default until somebody has.
 */
class MnotifyGateway implements ReportsDelivery, SmsGateway
{
    /**
     * mNotify's documented result codes.
     *
     * Kept here rather than inline so the ones that must NOT be retried are
     * visible as a set. Retrying an invalid sender ID a thousand times
     * consumes a thousand credits and delivers nothing.
     */
    private const CODE_SUCCESS = '2000';

    private const CODE_PENDING = '1000';

    private const CODE_SCHEDULED = '1007';

    /** @var array<string, string> code => what a person should do about it */
    private const PERMANENT_FAILURES = [
        '1002' => 'mNotify could not send the message.',
        '1003' => 'Insufficient SMS credit. The account needs topping up before anything else sends.',
        '1004' => 'The mNotify API key is invalid. Check MNOTIFY_API_KEY.',
        '1005' => 'mNotify rejected the phone number as invalid.',
        '1006' => 'mNotify rejected the sender ID. It must be registered with the networks, '
            .'exactly as registered, and no longer than 11 characters.',
        '1008' => 'The message body was empty.',
    ];

    public function name(): string
    {
        return 'mnotify';
    }

    public function send(SmsLog $log): SmsResult
    {
        try {
            $response = $this->client()->post('/sms/quick', [
                /*
                 * mNotify wants the number without a leading plus. Everything
                 * upstream stores E.164, so this is the one place that strips
                 * it — a provider's format quirk should not leak into the log.
                 */
                'recipient' => [ltrim((string) $log->to_number, '+')],
                'sender' => $log->sender_id,
                'message' => $log->body,
                'is_schedule' => false,
                'schedule_date' => '',
            ]);
        } catch (\Throwable $e) {
            // A network failure is worth retrying; the message has not been
            // charged for and has not been sent.
            return SmsResult::rejected('Could not reach mNotify: '.$e->getMessage());
        }

        /** @var array<string, mixed> $body */
        $body = (array) $response->json();
        $code = (string) ($body['code'] ?? '');

        if (! $response->successful()) {
            return SmsResult::rejected(
                "mNotify returned HTTP {$response->status()}.",
                $code !== '' ? $code : null,
                $this->scrub($body),
            );
        }

        if (isset(self::PERMANENT_FAILURES[$code])) {
            $this->warnIfAccountLevel($code, $log);

            return SmsResult::rejected(self::PERMANENT_FAILURES[$code], $code, $this->scrub($body));
        }

        if (! in_array($code, [self::CODE_SUCCESS, self::CODE_PENDING, self::CODE_SCHEDULED], true)) {
            /*
             * An unrecognised code is a failure, not a success. Treating an
             * unknown response as "probably fine" is how a provider change goes
             * unnoticed until somebody asks why nobody got their receipt.
             */
            return SmsResult::rejected(
                'mNotify returned an unrecognised response code ['.($code ?: 'none').'].',
                $code !== '' ? $code : null,
                $this->scrub($body),
            );
        }

        /** @var array<string, mixed> $summary */
        $summary = (array) ($body['summary'] ?? []);

        $this->recordBalance($summary);

        return SmsResult::accepted(
            // mNotify's campaign id. The only handle for asking later whether
            // the message actually arrived.
            providerMessageId: isset($summary['_id']) ? (string) $summary['_id'] : null,
            providerStatus: $code,
            raw: $this->scrub($body),
        );
    }

    /**
     * True — and this is the reason mNotify was worth choosing.
     *
     * Without delivery reports there is no way to tell a delivered message from
     * one the network dropped for an unregistered sender, because both look
     * identical from our side.
     */
    public function supportsDeliveryReports(): bool
    {
        return true;
    }

    /**
     * Ask mNotify what happened to a message it accepted.
     *
     * Returns null when there is nothing to say yet — no report, an
     * unrecognised shape, or a request that failed. Null means "still don't
     * know", and the log stays `sent`. It must never be read as "delivered".
     *
     * @return array{status: string, detail: string|null}|null
     */
    public function deliveryReport(string $providerMessageId): ?array
    {
        try {
            $response = $this->client()->get("/status/{$providerMessageId}");
        } catch (\Throwable $e) {
            Log::warning('Could not fetch an mNotify delivery report.', [
                'campaign' => $providerMessageId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        /** @var array<string, mixed> $body */
        $body = (array) $response->json();

        /*
         * mNotify returns a per-recipient report. One message is one recipient
         * here — the dispatcher sends individually so that suppression and
         * consent are checked per person — so the first row is the answer.
         */
        $report = $body['report'] ?? $body['summary'] ?? null;
        $first = is_array($report) ? (array) (array_values($report)[0] ?? $report) : null;

        if ($first === null) {
            return null;
        }

        $status = mb_strtolower((string) ($first['status'] ?? ''));

        return match (true) {
            str_contains($status, 'deliver') => ['status' => 'delivered', 'detail' => $status],
            str_contains($status, 'fail'),
            str_contains($status, 'reject'),
            str_contains($status, 'expire'),
            str_contains($status, 'undeliver') => ['status' => 'undelivered', 'detail' => $status],
            // Anything else — including "pending" and anything unrecognised —
            // means we still do not know.
            default => null,
        };
    }

    /**
     * Remaining SMS credits, or null if the balance could not be read.
     *
     * Surfaced on the admin dashboard. Credits are pre-paid and running out is
     * silent, so this is the difference between topping up on a Tuesday and
     * discovering on a Friday that a week of receipts never went.
     */
    public function balance(): ?int
    {
        try {
            $response = $this->client()->get('/balance/sms');
        } catch (\Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        /** @var array<string, mixed> $body */
        $body = (array) $response->json();
        $balance = $body['balance'] ?? ($body['data']['balance'] ?? null);

        return $balance === null ? null : (int) $balance;
    }

    private function client(): PendingRequest
    {
        $key = (string) config('communications.sms.mnotify.api_key', '');

        if ($key === '') {
            throw new RuntimeException(
                'MNOTIFY_API_KEY is not set, so no SMS can be sent. Set it, or set '
                .'SMS_DRIVER=log, which costs and records every message and sends none.'
            );
        }

        return Http::baseUrl((string) config('communications.sms.mnotify.base_url'))
            ->timeout((int) config('communications.sms.mnotify.timeout', 15))
            ->acceptJson()
            ->asJson()
            // mNotify takes the key as a query parameter rather than a header.
            // withQueryParameters puts it on every request from this client, so
            // no call site has to remember.
            ->withQueryParameters(['key' => $key]);
    }

    /**
     * Warn loudly about the failures that affect every message, not just one.
     *
     * An invalid number stops one message. An empty balance, a bad API key or a
     * rejected sender ID stops all of them — and the queue would otherwise fail
     * quietly, one message at a time, until somebody noticed.
     *
     * @param  array<string, mixed>  $context
     */
    private function warnIfAccountLevel(string $code, SmsLog $log, array $context = []): void
    {
        if (! in_array($code, ['1003', '1004', '1006'], true)) {
            return;
        }

        Log::critical('SMS is failing for the whole account, not just this message.', [
            'code' => $code,
            'meaning' => self::PERMANENT_FAILURES[$code],
            'sender_id' => $log->sender_id,
            'sms_log' => $log->ulid,
            ...$context,
        ]);
    }

    /** @param array<string, mixed> $summary */
    private function recordBalance(array $summary): void
    {
        $left = $summary['credit_left'] ?? null;

        if ($left === null) {
            return;
        }

        $threshold = (int) config('communications.sms.mnotify.low_balance_credits', 50);

        if ((int) $left <= $threshold) {
            Log::warning('mNotify SMS credit is running low.', [
                'credits_left' => (int) $left,
                'threshold' => $threshold,
            ]);
        }
    }

    /**
     * Keep the message body out of the stored payload.
     *
     * The body is already on the log row in its own column; a second copy
     * inside a JSON blob is one more place a prayer request or a beneficiary's
     * details has to be found and destroyed at retention time.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function scrub(array $body): array
    {
        unset($body['message'], $body['summary']['numbers_sent']);

        return $body;
    }
}
