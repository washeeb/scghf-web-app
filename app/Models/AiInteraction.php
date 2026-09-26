<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One call to a language model: what it cost, how long it took, and how it
 * ended.
 *
 * ── It records the shape of the call, never the words ───────────────────────
 *
 * No prompt, no answer, no visitor text. The conversation itself is in
 * `chat_messages`, where the retention rule can reach it; copying it here
 * would be a second copy of a stranger's personal data with a different
 * lifetime, which is exactly what Act 843 asks us not to do. What is here is
 * the operational record: tokens, milliseconds, pesewas, outcome.
 *
 * An answer can be linked from the message it produced, so a member of staff
 * reading a thread can mark one wrong — see `flagged`.
 */
class AiInteraction extends Model
{
    use HasFactory;
    use HasUlids;

    public const OUTCOME_ANSWERED = 'answered';

    public const OUTCOME_HANDOVER = 'handover';

    public const OUTCOME_FAILED = 'failed';

    protected $fillable = [
        'chat_conversation_id', 'driver', 'model', 'outcome', 'reason',
        'input_tokens', 'output_tokens', 'latency_ms', 'estimated_cost_minor',
    ];

    protected $attributes = [
        'input_tokens' => 0,
        'output_tokens' => 0,
        'latency_ms' => 0,
        'estimated_cost_minor' => 0,
        'flagged' => false,
    ];

    protected function casts(): array
    {
        return ['flagged' => 'boolean', 'flagged_at' => 'datetime'];
    }

    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return BelongsTo<ChatConversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'chat_conversation_id');
    }

    /** @return BelongsTo<User, $this> */
    public function flagger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'flagged_by');
    }

    /** Somebody in the office says this answer was wrong. */
    public function flag(User $by, ?string $note = null): void
    {
        $this->forceFill([
            'flagged' => true,
            'flag_note' => $note,
            'flagged_by' => $by->getKey(),
            'flagged_at' => now(),
        ])->save();
    }

    #[Scope]
    protected function thisMonth(Builder $query): void
    {
        $query->where('created_at', '>=', now()->startOfMonth());
    }
}
