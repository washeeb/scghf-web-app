<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A blog comment, awaiting moderation by default.
 *
 * Nothing appears without approval. An unmoderated comment form on a
 * foundation's site is both a spam target and a safeguarding surface — the
 * people most likely to comment on a beneficiary story are not always
 * well-intentioned.
 */
class Comment extends Model
{
    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_SPAM = 'spam';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'post_id', 'parent_id', 'user_id', 'author_name', 'author_email',
        'body', 'ip_address', 'user_agent',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => self::STATUS_PENDING,
    ];

    /** Never expose a commenter's email or IP in a public payload. */
    protected $hidden = ['author_email', 'ip_address', 'user_agent'];

    protected function casts(): array
    {
        return ['moderated_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $c) => $c->author_email = mb_strtolower(trim($c->author_email)));

        // The counter is denormalised so a post listing does not run a COUNT
        // per row. Kept in step here rather than by a scheduled reconciliation,
        // and incremented atomically so concurrent approvals cannot lose one.
        static::saved(function (self $comment): void {
            if ($comment->wasChanged('status') || $comment->wasRecentlyCreated) {
                $comment->post?->refreshCommentCount();
            }
        });

        static::deleted(fn (self $comment) => $comment->post?->refreshCommentCount());
    }

    /** @return array<int, string> */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    /** @return BelongsTo<Post, $this> */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /** @return BelongsTo<Comment, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<Comment, $this> */
    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->where('status', self::STATUS_APPROVED);
    }

    /** @return BelongsTo<User, $this> */
    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderated_by');
    }

    public function approve(User $moderator): void
    {
        $this->forceFill([
            'status' => self::STATUS_APPROVED,
            'moderated_by' => $moderator->getKey(),
            'moderated_at' => now(),
        ])->save();
    }

    public function markSpam(User $moderator): void
    {
        $this->forceFill([
            'status' => self::STATUS_SPAM,
            'moderated_by' => $moderator->getKey(),
            'moderated_at' => now(),
        ])->save();
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    #[Scope]
    protected function approved(Builder $query): void
    {
        $query->where('status', self::STATUS_APPROVED);
    }

    /** The moderation queue. */
    #[Scope]
    protected function awaitingModeration(Builder $query): void
    {
        $query->where('status', self::STATUS_PENDING)->orderBy('created_at');
    }

    /**
     * How many comments from this email address are already marked spam.
     *
     * A cheap signal that costs one indexed query and catches most repeat
     * offenders before a human has to look.
     */
    public static function spamCountFor(string $email): int
    {
        return static::withTrashed()
            ->where('author_email', mb_strtolower(trim($email)))
            ->where('status', self::STATUS_SPAM)
            ->count();
    }
}
