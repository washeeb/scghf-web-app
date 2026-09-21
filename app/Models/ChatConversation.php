<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\Retainable;
use App\Models\Concerns\DeIdentifiable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A live-chat conversation between a visitor and the office.
 *
 * ── The visitor's key ───────────────────────────────────────────────────────
 *
 * A visitor is not signed in, so what proves they own a conversation is a
 * secret the browser was given when the chat started. It is stored here as
 * a SHA-256, compared with `hash_equals`, and never shown again — the same
 * treatment a password gets. A signed-in visitor is linked to their account
 * as well, so their chats appear in their history without the token.
 *
 * ── Retention ───────────────────────────────────────────────────────────────
 *
 * Twelve months from the last message, then deleted with its messages
 * (config/compliance.php, `chat_conversation`). A chat is a conversation,
 * not a case file; the contact inbox exists for anything that needs to be
 * kept.
 */
class ChatConversation extends Model implements Retainable
{
    use DeIdentifiable;
    use HasFactory;
    use HasUlids;

    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'visitor_token_hash', 'visitor_name', 'visitor_email', 'user_id', 'assigned_to',
        'status', 'page_url', 'visitor_ip', 'last_message_at', 'last_visitor_message_at',
        'last_staff_message_at', 'staff_seen_at', 'visitor_seen_at', 'closed_at', 'closed_by',
    ];

    protected $attributes = ['status' => self::STATUS_OPEN];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'last_visitor_message_at' => 'datetime',
            'last_staff_message_at' => 'datetime',
            'staff_seen_at' => 'datetime',
            'visitor_seen_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return HasMany<ChatMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class)->orderBy('id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    /** True when the visitor has written since a member of staff last looked. */
    public function hasUnreadForStaff(): bool
    {
        return $this->last_visitor_message_at !== null
            && ($this->staff_seen_at === null || $this->last_visitor_message_at->gt($this->staff_seen_at));
    }

    #[Scope]
    protected function open(Builder $query): void
    {
        $query->where('status', self::STATUS_OPEN);
    }

    #[Scope]
    protected function unreadForStaff(Builder $query): void
    {
        $query->whereNotNull('last_visitor_message_at')
            ->where(fn (Builder $q) => $q->whereNull('staff_seen_at')->orWhereColumn('last_visitor_message_at', '>', 'staff_seen_at'));
    }

    // ── Retention ────────────────────────────────────────────────────────────

    public function retentionClass(): string
    {
        return 'chat_conversation';
    }

    public function retentionAnchorDate(): ?Carbon
    {
        $anchor = $this->last_message_at ?? $this->created_at;

        return $anchor === null ? null : Carbon::instance($anchor);
    }

    public function retentionScopeKey(): ?string
    {
        return null;
    }

    /** @return array<string, string> */
    public static function privacyElements(): array
    {
        return [
            'visitor_name' => 'name',
            'visitor_email' => 'email',
            'visitor_ip' => 'device',
            'visitor_token_hash' => 'device',
            'page_url' => 'device',
        ];
    }

    /** @return array<int, string> */
    public static function privacyExempt(): array
    {
        return [
            'id', 'ulid', 'user_id', 'assigned_to', 'status', 'last_message_at',
            'last_visitor_message_at', 'last_staff_message_at', 'staff_seen_at',
            'visitor_seen_at', 'closed_at', 'closed_by', 'created_at', 'updated_at',
        ];
    }

    /** The transcript is the personal data; it goes with the identifiers. */
    protected function deIdentifyRelated(): void
    {
        $this->messages()->delete();
    }
}
