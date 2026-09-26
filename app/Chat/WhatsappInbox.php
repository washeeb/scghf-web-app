<?php

declare(strict_types=1);

namespace App\Chat;

use App\Chat\Agent\ChatAgent;
use App\Models\ChatConversation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * A message arriving from WhatsApp becomes a line in a chat.
 *
 * ── Where this runs ─────────────────────────────────────────────────────────
 *
 * On the queue, from the webhook that already stores and verifies Meta's
 * payloads — not in the web request. The webhook has to answer 200 quickly
 * or Meta retries it, and an answer from the assistant can take several
 * seconds. On shared hosting the queue is a cron job every minute, so a
 * WhatsApp visitor can wait up to a minute for the first reply. That is the
 * right trade: a duplicated conversation from a retried webhook would be
 * worse than a minute's wait, and a person on WhatsApp is not watching a
 * typing indicator.
 *
 * ── One conversation per number, while it is open ───────────────────────────
 *
 * A visitor who writes again the next day continues the same chat if it is
 * still open, and starts a new one if it was closed. The 24-hour window is
 * pushed forward on every message from them, because that is exactly what it
 * measures.
 *
 * ── Nothing is ever sent to somebody who has not written first ──────────────
 *
 * A conversation can only come into being here, from an inbound message.
 * There is no path in this application that starts a WhatsApp chat with a
 * number, which is both Meta's rule and the decent one.
 */
class WhatsappInbox
{
    public function __construct(
        private readonly LiveChat $chat,
        private readonly ChatAgent $agent,
        private readonly WhatsappChat $whatsapp,
    ) {}

    /**
     * Handle one Meta webhook payload.
     *
     * @param  array<string, mixed>  $payload
     */
    public function receive(array $payload): void
    {
        if (! $this->whatsapp->enabled()) {
            return;
        }

        $value = (array) data_get($payload, 'entry.0.changes.0.value', []);
        $profiles = collect((array) data_get($value, 'contacts', []))
            ->mapWithKeys(fn (mixed $contact): array => is_array($contact)
                ? [(string) ($contact['wa_id'] ?? '') => (string) data_get($contact, 'profile.name', '')]
                : []);

        foreach ((array) data_get($value, 'messages', []) as $message) {
            if (! is_array($message)) {
                continue;
            }

            $this->one($message, $profiles->get((string) ($message['from'] ?? ''), ''));
        }
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function one(array $message, string $profileName): void
    {
        $from = trim((string) ($message['from'] ?? ''));
        $body = $this->body($message);

        if ($from === '' || $body === '') {
            return;
        }

        $conversation = $this->conversation($from, $profileName);

        // Every message from them reopens the window Meta measures.
        $conversation->forceFill([
            'whatsapp_window_expires_at' => now()->addHours(WhatsappChat::WINDOW_HOURS),
            'visitor_seen_at' => now(),
        ])->save();

        $this->chat->visitorReply($conversation, $body);

        if ($conversation->isWithAgent()) {
            $this->agent->respond($conversation->refresh(), $body);
        }
    }

    /**
     * What the visitor actually typed.
     *
     * Text only. A photograph, a voice note, a location or a contact card is
     * acknowledged as something a person has to look at — the assistant
     * cannot see any of it, and pretending an empty message arrived would
     * leave the sender waiting for an answer to something nobody read.
     *
     * @param  array<string, mixed>  $message
     */
    private function body(array $message): string
    {
        $type = (string) ($message['type'] ?? 'text');

        return match ($type) {
            'text' => trim((string) data_get($message, 'text.body', '')),
            'button' => trim((string) data_get($message, 'button.text', '')),
            'interactive' => trim((string) (data_get($message, 'interactive.button_reply.title')
                ?? data_get($message, 'interactive.list_reply.title')
                ?? '')),
            default => (string) __('[The visitor sent a :type. Open WhatsApp to see it.]', ['type' => $type]),
        };
    }

    private function conversation(string $waId, string $profileName): ChatConversation
    {
        $existing = ChatConversation::query()
            ->where('channel', ChatConversation::CHANNEL_WHATSAPP)
            ->where('whatsapp_wa_id', $waId)
            ->where('status', ChatConversation::STATUS_OPEN)
            ->latest('id')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($waId, $profileName): ChatConversation {
            $withAgent = $this->agent->shouldHandle();

            /*
             * The token is generated and thrown away. It is `not null unique`
             * on the table because a website visitor's whole claim to a
             * conversation is that secret; a WhatsApp visitor's claim is
             * their phone number, proved by Meta, so nothing will ever
             * present this one. A random value keeps the column honest —
             * a shared constant would collide on the second conversation.
             */
            $conversation = ChatConversation::create([
                'visitor_token_hash' => hash('sha256', Str::random(48)),
                'channel' => ChatConversation::CHANNEL_WHATSAPP,
                'whatsapp_wa_id' => $waId,
                'visitor_name' => Str::limit($profileName !== '' ? $profileName : __('WhatsApp visitor'), 120, ''),
                'handled_by' => $withAgent ? ChatConversation::HANDLER_AGENT : ChatConversation::HANDLER_STAFF,
                'visitor_seen_at' => now(),
                'whatsapp_window_expires_at' => now()->addHours(WhatsappChat::WINDOW_HOURS),
            ]);

            if ($withAgent && ($disclosure = trim((string) setting('agent.disclosure_line', ''))) !== '') {
                $this->chat->systemLine($conversation, $disclosure);
            }

            Log::info('Chat: a WhatsApp conversation was started.', ['conversation' => $conversation->ulid]);

            // The office hears about a WhatsApp chat exactly as it hears
            // about one started on the website.
            $this->chat->notifyOffice($conversation);

            return $conversation;
        });
    }
}
