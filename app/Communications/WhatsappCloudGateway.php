<?php

declare(strict_types=1);

namespace App\Communications;

use App\Communications\Contracts\WhatsappGateway;
use App\Models\SmsLog;
use App\Models\WhatsappTemplate;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Meta's WhatsApp Cloud API, directly — no BSP in between.
 *
 * One call: POST /{phone-number-id}/messages with a `template` object
 * (name, language, body parameters in order). The answer carries the
 * message id (`wamid.…`) that the status webhook later refers to. A
 * token, a phone number id and an app secret are the whole configuration;
 * all three come from Meta's Business Manager after verification, which
 * is the slow part and the reason the feature flag is off until they exist.
 */
class WhatsappCloudGateway implements WhatsappGateway
{
    public function name(): string
    {
        return 'cloud';
    }

    public function send(SmsLog $log, WhatsappTemplate $template, array $parameters): SmsResult
    {
        $token = (string) config('communications.whatsapp.access_token', '');
        $phoneId = (string) config('communications.whatsapp.phone_number_id', '');

        if ($token === '' || $phoneId === '') {
            return SmsResult::rejected('WhatsApp Cloud API is not configured (WHATSAPP_ACCESS_TOKEN, WHATSAPP_PHONE_NUMBER_ID).');
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => ltrim((string) $log->to_number, '+'),
            'type' => 'template',
            'template' => [
                'name' => $template->meta_name,
                'language' => ['code' => $template->language],
                'components' => $parameters === [] ? [] : [[
                    'type' => 'body',
                    'parameters' => array_map(fn (string $value): array => ['type' => 'text', 'text' => $value], $parameters),
                ]],
            ],
        ];

        try {
            $response = Http::withToken($token)
                ->timeout(15)
                ->acceptJson()
                ->post(sprintf('%s/%s/%s/messages', rtrim((string) config('communications.whatsapp.base_url', 'https://graph.facebook.com'), '/'), config('communications.whatsapp.api_version', 'v21.0'), $phoneId), $payload);
        } catch (Throwable $e) {
            return SmsResult::rejected('WhatsApp Cloud API unreachable: '.$e->getMessage());
        }

        $body = (array) $response->json();
        $id = data_get($body, 'messages.0.id');

        if (! $response->successful() || ! is_string($id) || $id === '') {
            $error = data_get($body, 'error.message') ?? 'HTTP '.$response->status();

            return SmsResult::rejected('WhatsApp refused the message: '.$error, (string) data_get($body, 'error.code', ''), $body);
        }

        return SmsResult::accepted(
            providerMessageId: $id,
            providerStatus: (string) data_get($body, 'messages.0.message_status', 'accepted'),
            raw: $body,
        );
    }

    public function reportsDelivery(): bool
    {
        return true;
    }
}
