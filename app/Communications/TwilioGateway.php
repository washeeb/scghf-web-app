<?php

declare(strict_types=1);

namespace App\Communications;

use App\Communications\Contracts\ReportsBalance;
use App\Communications\Contracts\ReportsDelivery;
use App\Communications\Contracts\SmsGateway;
use App\Models\SmsLog;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Twilio — the fallback, not the first choice.
 *
 * International routes into Ghana cost several times a local aggregator's
 * rate and an alphanumeric sender ID is not guaranteed on every network,
 * which is why it is last in the list. It is here because it is the one
 * gateway whose API nobody in Ghana has to be walked through, and because
 * a Ghanaian provider going dark for a week should not mean no receipts.
 *
 * `POST /Accounts/{sid}/Messages.json`, form-encoded, basic auth with the
 * account SID and token; `GET /Messages/{sid}.json` for the status;
 * `GET /Balance.json` for the account balance in its currency.
 */
class TwilioGateway implements ReportsBalance, ReportsDelivery, SmsGateway
{
    public function name(): string
    {
        return 'twilio';
    }

    public function send(SmsLog $log): SmsResult
    {
        try {
            $response = $this->client()->asForm()->post($this->accountPath('/Messages.json'), array_filter([
                'To' => (string) $log->to_number,
                'From' => $this->from($log),
                'MessagingServiceSid' => (string) config('communications.sms.twilio.messaging_service_sid', '') ?: null,
                'Body' => $log->body,
            ]));
        } catch (Throwable $e) {
            return SmsResult::rejected('Could not reach Twilio: '.$e->getMessage());
        }

        $body = (array) $response->json();

        if (! $response->successful()) {
            $message = (string) ($body['message'] ?? "Twilio returned HTTP {$response->status()}.");
            $code = isset($body['code']) ? (string) $body['code'] : null;

            // 20003 authentication, 21606/21612 sender not allowed, 20429 rate.
            if (in_array($code, ['20003', '21606', '21612'], true)) {
                Log::critical('SMS is failing for the whole account, not just this message.', ['gateway' => 'twilio', 'code' => $code, 'message' => $message, 'sms_log' => $log->ulid]);
            }

            return SmsResult::rejected($message, $code, $this->scrub($body));
        }

        // A 2xx with no message SID is not an accepted message. Twilio always
        // returns one; a body without it is a proxy page, a changed API, or
        // a response for something other than what was sent — and marking it
        // "sent" would hide that until somebody asks where their text went.
        if (! isset($body['sid']) || (string) $body['sid'] === '') {
            return SmsResult::rejected('Twilio answered without a message SID.', null, $this->scrub($body));
        }

        return SmsResult::accepted(
            providerMessageId: (string) $body['sid'],
            providerStatus: (string) ($body['status'] ?? 'queued'),
            raw: $this->scrub($body),
        );
    }

    public function supportsDeliveryReports(): bool
    {
        return true;
    }

    public function deliveryReport(string $providerMessageId): ?array
    {
        try {
            $response = $this->client()->get($this->accountPath('/Messages/'.$providerMessageId.'.json'));
        } catch (Throwable $e) {
            Log::warning('Could not fetch a Twilio delivery report.', ['sid' => $providerMessageId, 'error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $status = mb_strtolower((string) ($response->json('status') ?? ''));

        return match ($status) {
            'delivered' => ['status' => 'delivered', 'detail' => $status],
            'failed', 'undelivered', 'canceled' => ['status' => 'undelivered', 'detail' => $status.(($e = $response->json('error_message')) ? ': '.$e : '')],
            default => null,
        };
    }

    public function balance(): ?SmsBalance
    {
        try {
            $response = $this->client()->get($this->accountPath('/Balance.json'));
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful() || $response->json('balance') === null) {
            return null;
        }

        return SmsBalance::money((float) $response->json('balance'), (string) ($response->json('currency') ?? 'USD'));
    }

    private function from(SmsLog $log): ?string
    {
        // A messaging service carries its own senders; otherwise the number
        // or alphanumeric ID from config, falling back to the template's.
        if ((string) config('communications.sms.twilio.messaging_service_sid', '') !== '') {
            return null;
        }

        return (string) (config('communications.sms.twilio.from') ?: $log->sender_id);
    }

    private function accountPath(string $path): string
    {
        return '/Accounts/'.config('communications.sms.twilio.account_sid').$path;
    }

    private function client(): PendingRequest
    {
        $sid = (string) config('communications.sms.twilio.account_sid', '');
        $token = (string) config('communications.sms.twilio.auth_token', '');

        if ($sid === '' || $token === '') {
            throw new RuntimeException('TWILIO_ACCOUNT_SID and TWILIO_AUTH_TOKEN are not both set, so no SMS can be sent. Set them, or set SMS_DRIVER=log.');
        }

        return Http::baseUrl((string) config('communications.sms.twilio.base_url'))
            ->timeout((int) config('communications.sms.twilio.timeout', 15))
            ->acceptJson()
            ->withBasicAuth($sid, $token);
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    private function scrub(array $body): array
    {
        unset($body['body']);

        return $body;
    }
}
