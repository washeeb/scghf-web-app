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
use Throwable;

/**
 * Hubtel — the Ghanaian aggregator with the highest volume.
 *
 * Basic authentication with the client id and secret, `POST /messages/send`
 * with From, To and Content, `GET /messages/{id}` for the status. Hubtel
 * bills to a merchant account rather than a prepaid SMS credit pool and its
 * balance is not on the SMS API, so this gateway does not report one; the
 * health page says so rather than showing a number it cannot read.
 */
class HubtelGateway implements ReportsDelivery, SmsGateway
{
    public function name(): string
    {
        return 'hubtel';
    }

    public function send(SmsLog $log): SmsResult
    {
        try {
            $response = $this->client()->post('/messages/send', [
                'From' => $log->sender_id,
                'To' => (string) $log->to_number,
                'Content' => $log->body,
                'RegisteredDelivery' => true,
            ]);
        } catch (Throwable $e) {
            return SmsResult::rejected('Could not reach Hubtel: '.$e->getMessage());
        }

        $body = (array) $response->json();
        $status = (string) ($body['Status'] ?? $body['status'] ?? '');

        // Hubtel: 0 accepted, 1 undeliverable, 2 sender rejected, 3 insufficient balance, 4 rate limited …
        if (! $response->successful() || ! in_array($status, ['0', '1'], true)) {
            $message = (string) ($body['Message'] ?? $body['message'] ?? "Hubtel returned HTTP {$response->status()} (status {$status}).");

            if (in_array($status, ['2', '3'], true)) {
                Log::critical('SMS is failing for the whole account, not just this message.', ['gateway' => 'hubtel', 'status' => $status, 'message' => $message, 'sms_log' => $log->ulid]);
            }

            return SmsResult::rejected($message, $status ?: null, $this->scrub($body));
        }

        return SmsResult::accepted(
            providerMessageId: isset($body['MessageId']) ? (string) $body['MessageId'] : (isset($body['messageId']) ? (string) $body['messageId'] : null),
            providerStatus: $status,
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
            $response = $this->client()->get('/messages/'.$providerMessageId);
        } catch (Throwable $e) {
            Log::warning('Could not fetch a Hubtel delivery report.', ['id' => $providerMessageId, 'error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $status = mb_strtolower((string) ($response->json('Status') ?? $response->json('status') ?? ''));

        return match (true) {
            str_contains($status, 'deliver') => ['status' => 'delivered', 'detail' => $status],
            str_contains($status, 'fail'), str_contains($status, 'reject'), str_contains($status, 'expire'), str_contains($status, 'undeliver') => ['status' => 'undelivered', 'detail' => $status],
            default => null,
        };
    }

    private function client(): PendingRequest
    {
        $id = (string) config('communications.sms.hubtel.client_id', '');
        $secret = (string) config('communications.sms.hubtel.client_secret', '');

        if ($id === '' || $secret === '') {
            throw new RuntimeException('HUBTEL_CLIENT_ID and HUBTEL_CLIENT_SECRET are not both set, so no SMS can be sent. Set them, or set SMS_DRIVER=log.');
        }

        return Http::baseUrl((string) config('communications.sms.hubtel.base_url'))
            ->timeout((int) config('communications.sms.hubtel.timeout', 15))
            ->acceptJson()
            ->asJson()
            ->withBasicAuth($id, $secret);
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    private function scrub(array $body): array
    {
        unset($body['Content'], $body['content']);

        return $body;
    }
}
