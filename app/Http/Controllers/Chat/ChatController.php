<?php

declare(strict_types=1);

namespace App\Http\Controllers\Chat;

use App\Chat\LiveChat;
use App\Http\Controllers\Controller;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The visitor's end of the live chat: four small JSON endpoints the widget
 * calls. Every request after the first carries the conversation's token in
 * `X-Chat-Token`; a wrong or missing token is a 404, not a 403, so the
 * endpoint does not confirm which conversations exist.
 */
class ChatController extends Controller
{
    public function __construct(private readonly LiveChat $chat) {}

    /** Whether the office is there, for the widget's status line. */
    public function status(): JsonResponse
    {
        abort_unless($this->chat->enabled(), 404);

        return response()->json(['online' => $this->chat->staffOnline()]);
    }

    public function start(Request $request): JsonResponse
    {
        abort_unless($this->chat->enabled(), 404);

        $requireEmail = (bool) setting('chat.require_email', true) && ! $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => [Rule::requiredIf($requireEmail), 'nullable', 'email:rfc', 'max:191'],
            'message' => ['required', 'string', 'max:'.ChatMessage::MAX_LENGTH],
            'page' => ['nullable', 'string', 'max:500'],
            // The honeypot: a field no person sees. Filled means a bot; answered
            // as success so the bot learns nothing, stored nowhere.
            'website' => ['nullable', 'string', 'max:0'],
        ]);

        $user = $request->user();

        $started = $this->chat->start(
            name: $user?->name ?: $data['name'],
            email: $data['email'] ?? null,
            message: $data['message'],
            user: $user,
            ip: $request->ip(),
            pageUrl: $data['page'] ?? null,
        );

        return response()->json([
            'id' => $started['conversation']->ulid,
            'token' => $started['token'],
            'online' => $this->chat->staffOnline(),
            'messages' => $started['conversation']->messages->map(fn (ChatMessage $m): array => $m->toWidgetArray()),
        ], 201);
    }

    /** New lines since `after`, and the conversation's state. */
    public function messages(Request $request, ChatConversation $conversation): JsonResponse
    {
        $this->guard($request, $conversation);

        $after = max(0, (int) $request->query('after', 0));

        $messages = $conversation->messages()->with('author')->where('id', '>', $after)->get();

        $conversation->forceFill(['visitor_seen_at' => now()])->saveQuietly();

        return response()->json([
            'status' => $conversation->status,
            'online' => $this->chat->staffOnline(),
            'agent' => $conversation->assignee ? (string) str((string) $conversation->assignee->name)->before(' ') : null,
            'messages' => $messages->map(fn (ChatMessage $m): array => $m->toWidgetArray()),
        ]);
    }

    public function send(Request $request, ChatConversation $conversation): JsonResponse
    {
        $this->guard($request, $conversation);

        abort_unless($conversation->isOpen(), 409, __('This chat has been closed.'));

        $data = $request->validate(['body' => ['required', 'string', 'max:'.ChatMessage::MAX_LENGTH]]);

        $message = $this->chat->visitorReply($conversation, $data['body']);

        return response()->json(['message' => $message->toWidgetArray()], 201);
    }

    public function close(Request $request, ChatConversation $conversation): JsonResponse
    {
        $this->guard($request, $conversation);

        $this->chat->close($conversation);

        return response()->json(['status' => ChatConversation::STATUS_CLOSED]);
    }

    private function guard(Request $request, ChatConversation $conversation): void
    {
        abort_unless($this->chat->enabled(), 404);
        abort_unless($this->chat->authorise($conversation, $request->header('X-Chat-Token')), 404);
    }
}
