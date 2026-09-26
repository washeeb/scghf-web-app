<?php

declare(strict_types=1);

namespace App\Chat;

use App\Chat\Agent\ChatAgent;
use App\Communications\MessageDispatcher;
use App\Filament\Resources\ChatConversations\ChatConversationResource;
use App\Models\AiInteraction;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ContactDepartment;
use App\Models\User;
use App\Support\Features;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Live chat between a visitor and the office.
 *
 * ── Held here, polled, no third party ───────────────────────────────────────
 *
 * Nothing a visitor types goes to a chat vendor; it is a row in this
 * database, answered from the admin inbox. The visitor's page asks for new
 * lines every few seconds (shared hosting has no websocket to offer), which
 * for a conversation of a dozen lines is a handful of tiny requests. A member
 * of staff counts as online while they have the chat inbox open — the panel
 * touches their presence on every poll — and the widget says "online" or
 * "away" from that, honestly, rather than pretending somebody is there.
 *
 * ── Two switches ────────────────────────────────────────────────────────────
 *
 * `FEATURE_LIVE_CHAT` in .env says whether the module exists on this
 * install; Settings → Live chat → "Show the chat" is the editor's daily
 * switch. Both must be on for the widget to render.
 */
class LiveChat
{
    /** How long after their last poll a member of staff still counts as online. */
    public const PRESENCE_MINUTES = 3;

    public function __construct(
        private readonly Features $features,
        private readonly MessageDispatcher $dispatcher,
    ) {}

    public function enabled(): bool
    {
        return $this->features->enabled('live_chat') && (bool) setting('chat.enabled', true);
    }

    // ── Presence ─────────────────────────────────────────────────────────────

    /** Called by the admin chat screens on every poll. */
    public function touchPresence(User $user): void
    {
        $online = $this->presence();
        $online[$user->getKey()] = now()->timestamp;

        Cache::put('chat:presence', $online, now()->addHours(2));
    }

    public function staffOnline(): bool
    {
        $cutoff = now()->subMinutes(self::PRESENCE_MINUTES)->timestamp;

        foreach ($this->presence() as $timestamp) {
            if ((int) $timestamp >= $cutoff) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, int> user id => unix timestamp of last poll */
    private function presence(): array
    {
        $online = Cache::get('chat:presence', []);

        return is_array($online) ? $online : [];
    }

    // ── The visitor's side ───────────────────────────────────────────────────

    /**
     * Start a conversation. Returns it with the plain token the browser keeps.
     *
     * @return array{conversation: ChatConversation, token: string}
     */
    public function start(string $name, ?string $email, string $message, ?User $user, ?string $ip, ?string $pageUrl): array
    {
        $token = Str::random(48);
        $withAgent = app(ChatAgent::class)->shouldHandle();

        $conversation = DB::transaction(function () use ($name, $email, $message, $user, $ip, $pageUrl, $token, $withAgent): ChatConversation {
            $conversation = ChatConversation::create([
                'visitor_token_hash' => hash('sha256', $token),
                'visitor_name' => Str::limit(trim($name), 120, ''),
                'visitor_email' => $email ? Str::lower(trim($email)) : ($user?->email),
                'user_id' => $user?->getKey(),
                'page_url' => $pageUrl ? Str::limit($pageUrl, 500, '') : null,
                'visitor_ip' => $ip,
                'visitor_seen_at' => now(),
                'handled_by' => $withAgent ? ChatConversation::HANDLER_AGENT : ChatConversation::HANDLER_STAFF,
            ]);

            /*
             * Before the first answer, not after it, and not in a policy page
             * nobody reads: somebody asking a charity for help is entitled to
             * know whether they are talking to one.
             */
            if ($withAgent && ($disclosure = trim((string) setting('agent.disclosure_line', ''))) !== '') {
                $this->append($conversation, ChatMessage::SENDER_SYSTEM, $disclosure);
            }

            $this->append($conversation, ChatMessage::SENDER_VISITOR, $message);

            return $conversation;
        });

        $this->notifyOffice($conversation);

        return ['conversation' => $conversation->refresh(), 'token' => $token];
    }

    /** Whether this token is the one the conversation was started with. */
    public function authorise(ChatConversation $conversation, ?string $token): bool
    {
        if ($token === null || $token === '') {
            return false;
        }

        return hash_equals($conversation->visitor_token_hash, hash('sha256', $token));
    }

    public function visitorReply(ChatConversation $conversation, string $body): ChatMessage
    {
        return $this->append($conversation, ChatMessage::SENDER_VISITOR, $body);
    }

    // ── The office's side ────────────────────────────────────────────────────

    public function staffReply(ChatConversation $conversation, User $user, string $body): ChatMessage
    {
        $changes = [];

        if ($conversation->assigned_to === null) {
            $changes['assigned_to'] = $user->getKey();
        }

        // A person typing ends the assistant's involvement, permanently. Two
        // of them answering the same visitor is the worst version of this.
        if ($conversation->isWithAgent()) {
            $changes['handled_by'] = ChatConversation::HANDLER_STAFF;
            $changes['escalated_at'] = $conversation->escalated_at ?? now();
            $changes['escalation_reason'] = $conversation->escalation_reason ?? 'staff_took_over';
        }

        if ($changes !== []) {
            $conversation->forceFill($changes)->save();
        }

        $message = $this->append($conversation, ChatMessage::SENDER_STAFF, $body, $user);

        $this->deliver($conversation, $message);

        return $message;
    }

    /**
     * A line from the assistant.
     *
     * Counted, so the per-conversation ceiling in the settings means
     * something, and linked to the call that produced it so a member of
     * staff reading the thread can mark that answer wrong.
     */
    public function agentReply(ChatConversation $conversation, string $body, ?AiInteraction $interaction = null): ChatMessage
    {
        $conversation->forceFill(['agent_replies' => $conversation->agent_replies + 1])->save();

        $message = $this->append($conversation, ChatMessage::SENDER_AGENT, $body, null, $interaction);

        $this->deliver($conversation, $message);

        return $message;
    }

    /** A line from the application itself: a disclosure, a hand-over, a closure. */
    public function systemLine(ChatConversation $conversation, string $body): ChatMessage
    {
        $message = $this->append($conversation, ChatMessage::SENDER_SYSTEM, $body);

        $this->deliver($conversation, $message);

        return $message;
    }

    /**
     * Put an outgoing line where the visitor actually is.
     *
     * On the website they are polling and there is nothing to do. On
     * WhatsApp it has to be sent, and Meta refuses a free-form message more
     * than 24 hours after the visitor's last one — WhatsappChat deals with
     * that; this only has to know that it is not always a no-op.
     */
    private function deliver(ChatConversation $conversation, ChatMessage $message): void
    {
        if (! $conversation->isOnWhatsapp()) {
            return;
        }

        try {
            app(WhatsappChat::class)->send($conversation, $message);
        } catch (Throwable $e) {
            report($e);
            Log::warning('Chat: a WhatsApp reply was not delivered.', ['conversation' => $conversation->ulid, 'error' => $e->getMessage()]);
        }
    }

    public function assign(ChatConversation $conversation, User $user): void
    {
        $conversation->forceFill(['assigned_to' => $user->getKey()])->save();
    }

    /**
     * End the conversation, from either side.
     *
     * A closing line is written so both sides can see it ended rather than
     * wondering; a transcript goes to the visitor's email when the setting
     * says so and there is an address to send it to.
     */
    public function close(ChatConversation $conversation, ?User $by = null): void
    {
        if (! $conversation->isOpen()) {
            return;
        }

        $line = $by
            ? (string) setting('chat.closed_by_staff_line', __('This chat has been closed. Thank you for talking to us.'))
            : (string) setting('chat.closed_by_visitor_line', __('The visitor ended the chat.'));

        DB::transaction(function () use ($conversation, $by, $line): void {
            $this->append($conversation, ChatMessage::SENDER_SYSTEM, $line);

            $conversation->forceFill([
                'status' => ChatConversation::STATUS_CLOSED,
                'closed_at' => now(),
                'closed_by' => $by?->getKey(),
            ])->save();
        });

        $this->sendTranscript($conversation);
    }

    public function reopen(ChatConversation $conversation): void
    {
        $conversation->forceFill(['status' => ChatConversation::STATUS_OPEN, 'closed_at' => null, 'closed_by' => null])->save();
    }

    // ── Internals ────────────────────────────────────────────────────────────

    private function append(ChatConversation $conversation, string $sender, string $body, ?User $user = null, ?AiInteraction $interaction = null): ChatMessage
    {
        $body = Str::limit(trim($body), ChatMessage::MAX_LENGTH, '');

        $message = new ChatMessage([
            'sender' => $sender,
            'user_id' => $user?->getKey(),
            'ai_interaction_id' => $interaction?->getKey(),
            'body' => $body,
            'created_at' => now(),
        ]);
        $conversation->messages()->save($message);

        $conversation->forceFill(array_filter([
            'last_message_at' => now(),
            'last_visitor_message_at' => $sender === ChatMessage::SENDER_VISITOR ? now() : null,
            'last_staff_message_at' => $sender === ChatMessage::SENDER_STAFF ? now() : null,
            /*
             * The assistant answering does NOT mark the chat as seen. The
             * bell in the inbox means "no person has looked at this", and a
             * machine looking at it is not a person looking at it.
             */
            'staff_seen_at' => $sender === ChatMessage::SENDER_STAFF ? now() : null,
        ]))->save();

        return $message;
    }

    /**
     * Tell the office that a conversation now needs a person.
     *
     * To the department's own address when the escalation named one — that
     * is what the departments routing table is for, with its own SLA and its
     * own confidentiality — and to the chat address otherwise.
     */
    public function notifyHandover(ChatConversation $conversation, string $reason, ?ContactDepartment $department = null): void
    {
        $to = (string) ($department?->email
            ?: setting('chat.notify_email')
            ?: setting('contact.email_general')
            ?: '');

        // A seeded placeholder is not an address. Better no email than a
        // bounce nobody reads.
        if ($to === '' || str_contains($to, '{{')) {
            return;
        }

        try {
            $this->dispatcher->queueEmail('chat.handover', $to, [
                'name' => $conversation->visitor_name,
                'email' => $conversation->visitor_email ?? __('(no email given)'),
                'reason' => $reason,
                'department' => $department === null ? __('General') : $department->name,
                'channel' => $conversation->isOnWhatsapp() ? __('WhatsApp') : __('the website'),
                'transcript' => $this->transcript($conversation, 12),
                'admin_url' => ChatConversationResource::getUrl('view', ['record' => $conversation]),
            ], ['idempotency_key' => 'chat.handover:'.$conversation->ulid.':'.now()->format('YmdH')]);
        } catch (Throwable $e) {
            report($e);
            Log::warning('Chat: the hand-over email was not queued.', ['conversation' => $conversation->ulid, 'error' => $e->getMessage()]);
        }
    }

    /** The conversation as plain text, for an email. */
    public function transcript(ChatConversation $conversation, ?int $lastLines = null): string
    {
        $query = ChatMessage::query()->where('chat_conversation_id', $conversation->getKey())->with('author');

        $messages = $lastLines === null
            ? $query->orderBy('id')->get()
            : $query->orderByDesc('id')->limit($lastLines)->get()->reverse();

        return $messages->map(function (ChatMessage $m) use ($conversation): string {
            $who = match ($m->sender) {
                ChatMessage::SENDER_STAFF => (string) ($m->author === null || $m->author->name === '' ? setting('general.short_name', config('app.name')) : $m->author->name),
                ChatMessage::SENDER_AGENT => (string) setting('agent.name', __('Assistant')),
                ChatMessage::SENDER_VISITOR => $conversation->visitor_name,
                default => '—',
            };

            return $m->created_at?->format('H:i').' '.$who.': '.$m->body;
        })->implode("\n");
    }

    /**
     * Tell the office a chat has started, by email, once per conversation.
     *
     * Whether or not somebody is online: an inbox tab in the background is
     * not a guarantee anybody is looking at it. Reports rather than throws —
     * a broken template must not stop a visitor's chat from starting.
     */
    public function notifyOffice(ChatConversation $conversation): void
    {
        $to = (string) (setting('chat.notify_email') ?: setting('contact.email_general') ?: '');

        if ($to === '') {
            return;
        }

        try {
            $this->dispatcher->queueEmail('chat.new_conversation', $to, [
                'name' => $conversation->visitor_name,
                'email' => $conversation->visitor_email ?? __('(no email given)'),
                'message' => (string) ChatMessage::query()->where('chat_conversation_id', $conversation->getKey())->orderBy('id')->value('body'),
                'page_url' => (string) $conversation->page_url,
                'online' => $this->staffOnline() ? __('somebody has the chat inbox open') : __('nobody has the chat inbox open'),
                'admin_url' => ChatConversationResource::getUrl('view', ['record' => $conversation]),
            ], ['idempotency_key' => 'chat.new_conversation:'.$conversation->ulid]);
        } catch (Throwable $e) {
            report($e);
            Log::warning('Chat: the new-conversation email was not queued.', ['conversation' => $conversation->ulid, 'error' => $e->getMessage()]);
        }
    }

    private function sendTranscript(ChatConversation $conversation): void
    {
        if (! (bool) setting('chat.send_transcript', true) || blank($conversation->visitor_email)) {
            return;
        }

        $lines = $this->transcript($conversation);

        try {
            $this->dispatcher->queueEmail('chat.transcript', (string) $conversation->visitor_email, [
                'name' => $conversation->visitor_name,
                'transcript' => $lines,
                'date' => $conversation->created_at?->format('j F Y'),
            ], ['idempotency_key' => 'chat.transcript:'.$conversation->ulid]);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
