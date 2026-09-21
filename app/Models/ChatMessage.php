<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a live chat.
 *
 * `sender` says who wrote it: the visitor, a member of staff (with
 * `user_id`), or the system (a greeting, "the chat was closed"). The body is
 * plain text; it is escaped on the way out, never rendered as HTML.
 */
class ChatMessage extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    public const SENDER_VISITOR = 'visitor';

    public const SENDER_STAFF = 'staff';

    public const SENDER_SYSTEM = 'system';

    /** Longer than anybody types in a chat; short enough to stop a paste bomb. */
    public const MAX_LENGTH = 2000;

    protected $fillable = ['chat_conversation_id', 'sender', 'user_id', 'body', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    /** @return BelongsTo<ChatConversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'chat_conversation_id');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isFromStaff(): bool
    {
        return $this->sender === self::SENDER_STAFF;
    }

    /** What the visitor's widget shows for this line. */
    public function toWidgetArray(): array
    {
        return [
            'id' => $this->getKey(),
            'sender' => $this->sender,
            'body' => $this->body,
            'at' => $this->created_at?->toIso8601String(),
            // A first name is enough for a visitor; a surname is for the office.
            'name' => $this->isFromStaff() ? (string) str((string) $this->author?->name)->before(' ') : null,
        ];
    }
}
