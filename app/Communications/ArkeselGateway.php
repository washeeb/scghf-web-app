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
 * Arkesel — the second Ghanaian gateway.
 *
 * The v2 API: the key in an `api-key` header, `POST /sms/send` with the
 * sender, the message and a list of recipients, `GET /sms/{id}` for a
 * delivery report, `GET /clients/balance-details` for the credits. The
 * shape follows Arkesel's published documentation; the endpoints are
 * configuration, so a change on their side is an `.env` line.
 *
 * Same discipline as mNotify: acceptance is not delivery, the body is not
 * stored in the raw response, and an account-level refusal is logged as
 * critical because every other message is about to fail the same way.
 */
class ArkeselGateway implements ReportsBalance, ReportsDelivery, SmsGateway
{
    public function name(): string
    {
        return 'arkesel';
    }

    public function send(SmsLog $log): SmsResult
    {
        try {
            $response = $this->client()->post('/sms/send', [
                'sender' => $log->sender_id,
                'message' => $log->body,
                'recipients' => [ltrim((string) $log->to_number, '+')],
            ]);
        } catch (Throwable $e) {
            return SmsResult::rejected('Could not reach Arkesel: '.$e->getMessage());
        }

        $body = (array) $response->json();
        $status = (string) ($body['status'] ?? '');

        if (! $response->successful() || $status !== 'success') {
            $message = (string) ($body['message'] ?? "Arkesel returned HTTP {$response->status()}.");

            if (str_contains(mb_strtolower($message), 'balance') || str_contains(mb_strtolower($message), 'sender')) {
                Log::critical('SMS is failing for the whole account, not just this message.', ['gateway' => 'arkesel', 'message' => $message, 'sms_log' => $log->ulid]);
            }

            return SmsResult::rejected($message, $status ?: null, $this->scrub($body));
        }

        $first = (array) (($body['data'] ?? [])[0] ?? []);

        return SmsResult::accepted(
            providerMessageId: isset($first['id']) ? (string) $first['id'] : null,
            providerStatus: (string) ($first['status'] ?? $status),
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
            $response = $this->client()->get('/sms/'.$providerMessageId);
        } catch (Throwable $e) {
            Log::warning('Could not fetch an Arkesel delivery report.', ['id' => $providerMessageId, 'error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $data = (array) ($response->json('data') ?? []);
        $status = mb_strtolower((string) ($data['status'] ?? ''));

        return match (true) {
            str_contains($status, 'deliver') => ['status' => 'delivered', 'detail' => $status],
            str_contains($status, 'fail'), str_contains($status, 'reject'), str_contains($status, 'expire'), str_contains($status, 'undeliver') => ['status' => 'undelivered', 'detail' => $status],
            default => null,
        };
    }

    public function balance(): ?SmsBalance
    {
        try {
            $response = $this->client()->get('/clients/balance-details');
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $balance = $response->json('data.sms_balance') ?? $response->json('data.balance');

        return $balance === null ? null : SmsBalance::credits((float) $balance);
    }

    private function client(): PendingRequest
    {
        $key = (string) config('communications.sms.arkesel.api_key', '');

        if ($key === '') {
            throw new RuntimeException('ARKESEL_API_KEY is not set, so no SMS can be sent. Set it, or set SMS_DRIVER=log.');
        }

        return Http::baseUrl((string) config('communications.sms.arkesel.base_url'))
            ->timeout((int) config('communications.sms.arkesel.timeout', 15))
            ->acceptJson()
            ->asJson()
            ->withHeaders(['api-key' => $key]);
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    private function scrub(array $body): array
    {
        unset($body['message_body'], $body['data']['message']);

        return $body;
    }
}
