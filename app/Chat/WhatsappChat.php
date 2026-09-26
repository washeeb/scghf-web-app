<?php

declare(strict_types=1);

namespace App\Chat;

use App\Communications\WhatsappCloudGateway;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\SmsLog;
use App\Models\WhatsappTemplate;
use App\Support\Features;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * The live chat, over WhatsApp.
 *
 * ── Why a visitor on WhatsApp is the same conversation ──────────────────────
 *
 * The office should not have two inboxes. A message to the foundation's
 * WhatsApp number becomes a `ChatConversation` with `channel = whatsapp`,
 * lands in the same admin screen with the same assistant in front of it and
 * the same escalation rules, and a reply typed in that screen goes back out
 * over WhatsApp. The only thing that differs is how the words travel.
 *
 * ── Meta's 24-hour window is a hard rule, not a nicety ──────────────────────
 *
 * Free-form text may only be sent within 24 hours of the visitor's last
 * message. Outside it, Meta rejects anything that is not a pre-approved
 * template — so a reply written at nine the next morning to a question asked
 * at eight the previous evening will simply not arrive unless it goes as a
 * template. This sends the free-form message while the window is open, and
 * the `chat.reply` template when it is not, and records plainly which it
 * was. Silently dropping a member of staff's reply would be the worst
 * outcome available.
 *
 * ── A number is a person's phone number ─────────────────────────────────────
 *
 * `whatsapp_wa_id` is personal data under Act 843 and is classified as such
 * on the model. It is never used to look anything up about the sender, and
 * nothing is ever sent to a number that has not written to us first.
 */
class WhatsappChat
{
    /** Meta's customer-service window. */
    public const WINDOW_HOURS = 24;

    public function __construct(private readonly Features $features) {}

    public function enabled(): bool
    {
        return $this->features->enabled('whatsapp')
            && (bool) setting('chat.whatsapp_enabled', false)
            && filled(config('communications.whatsapp.access_token'))
            && filled(config('communications.whatsapp.phone_number_id'));
    }

    /**
     * Send one line of the conversation to the visitor's phone.
     *
     * System lines are sent too: a hand-over that only the website can see
     * is not a hand-over to somebody on WhatsApp.
     */
    public function send(ChatConversation $conversation, ChatMessage $message): void
    {
        if (! $this->enabled() || ! $conversation->isOnWhatsapp() || blank($conversation->whatsapp_wa_id)) {
            return;
        }

        if ($message->sender === ChatMessage::SENDER_VISITOR) {
            return;
        }

        $body = $this->prefix($message).$message->body;

        $conversation->whatsappWindowOpen()
            ? $this->sendText($conversation, $body, $message)
            : $this->sendTemplate($conversation, $body, $message);
    }

    /**
     * Who is speaking, when it is not obvious.
     *
     * On the website the assistant's lines are drawn differently from a
     * person's. On WhatsApp every message looks the same, so the only way a
     * visitor can tell a machine from a member of staff is if it says.
     */
    private function prefix(ChatMessage $message): string
    {
        return match ($message->sender) {
            ChatMessage::SENDER_AGENT => (string) setting('agent.name', __('Assistant')).': ',
            ChatMessage::SENDER_STAFF => trim((string) str((string) $message->author?->name)->before(' ')) !== ''
                ? (string) str((string) $message->author?->name)->before(' ').': '
                : '',
            default => '',
        };
    }

    private function sendText(ChatConversation $conversation, string $body, ChatMessage $message): void
    {
        $log = $this->log($conversation, $body, 'chat.reply', $message);

        try {
            $response = Http::withToken((string) config('communications.whatsapp.access_token'))
                ->timeout(15)
                ->acceptJson()
                ->post($this->endpoint(), [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => ltrim((string) $conversation->whatsapp_wa_id, '+'),
                    'type' => 'text',
                    'text' => ['preview_url' => false, 'body' => Str::limit($body, 4_000, '')],
                ]);
        } catch (Throwable $e) {
            report($e);
            $this->fail($log, 'WhatsApp unreachable: '.$e->getMessage());

            return;
        }

        $id = data_get((array) $response->json(), 'messages.0.id');

        if (! $response->successful() || ! is_string($id)) {
            $this->fail($log, 'WhatsApp refused the message: '.(string) data_get((array) $response->json(), 'error.message', 'HTTP '.$response->status()));

            return;
        }

        $log->forceFill([
            'status' => SmsLog::STATUS_SENT,
            'provider_message_id' => $id,
            'sent_at' => now(),
        ])->save();
    }

    /**
     * Outside the window: the approved template, with the reply as its one
     * parameter.
     *
     * If the foundation has not had `chat.reply` approved by Meta yet, the
     * message is recorded as blocked with the reason, so the office can see
     * why their answer did not arrive rather than assuming it did.
     */
    private function sendTemplate(ChatConversation $conversation, string $body, ChatMessage $message): void
    {
        $log = $this->log($conversation, $body, 'chat.reply', $message);

        $template = WhatsappTemplate::query()
            ->where('key', 'chat.reply')
            ->where('is_active', true)
            ->whereNotNull('meta_name')
            ->first();

        if ($template === null) {
            $this->fail($log, 'Outside the 24-hour window and no approved chat.reply template.');

            return;
        }

        try {
            $result = app(WhatsappCloudGateway::class)->send($log, $template, [Str::limit($body, 900, '')]);
        } catch (Throwable $e) {
            report($e);
            $this->fail($log, 'WhatsApp template send failed: '.$e->getMessage());

            return;
        }

        $result->successful
            ? $log->forceFill(['status' => SmsLog::STATUS_SENT, 'provider_message_id' => $result->providerMessageId, 'sent_at' => now()])->save()
            : $this->fail($log, (string) $result->message);
    }

    private function log(ChatConversation $conversation, string $body, string $templateKey, ChatMessage $message): SmsLog
    {
        return SmsLog::create([
            'template_key' => $templateKey,
            'category' => 'transactional',
            'channel' => SmsLog::CHANNEL_WHATSAPP,
            'driver' => 'cloud',
            // The column is NOT NULL: an SMS carries a registered sender ID,
            // and on WhatsApp the equivalent is the business number itself.
            'sender_id' => (string) (setting('contact.whatsapp') ?: config('communications.whatsapp.phone_number_id', 'whatsapp')),
            'to_number' => (string) $conversation->whatsapp_wa_id,
            'body' => Str::limit($body, 1_000, ''),
            'estimated_cost_minor' => (int) config('communications.whatsapp.cost_per_message_minor', 0),
            'related_type' => $conversation->getMorphClass(),
            'related_id' => $conversation->getKey(),
            'user_id' => $message->user_id,
            'queued_at' => now(),
        ]);
    }

    private function fail(SmsLog $log, string $error): void
    {
        $log->forceFill([
            'status' => SmsLog::STATUS_FAILED,
            'error' => Str::limit($error, 500, ''),
            'failed_at' => now(),
        ])->save();

        Log::warning('Chat: a WhatsApp message was not sent.', ['log' => $log->getKey(), 'error' => $error]);
    }

    private function endpoint(): string
    {
        return sprintf(
            '%s/%s/%s/messages',
            rtrim((string) config('communications.whatsapp.base_url', 'https://graph.facebook.com'), '/'),
            config('communications.whatsapp.api_version', 'v21.0'),
            config('communications.whatsapp.phone_number_id'),
        );
    }
}
