<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PageStatus;
use App\Models\Concerns\BelongsToDivision;
use App\Models\Concerns\HasSeo;
use App\Models\Concerns\RecordsAuthor;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * @property PageStatus $status
 */
class Post extends Model
{
    use BelongsToDivision;
    use HasFactory;
    use HasSeo;
    use HasUlids;
    use LogsActivity;
    use RecordsAuthor;
    use SoftDeletes;

    protected $fillable = [
        'division_id', 'blog_category_id', 'author_id', 'title', 'slug', 'excerpt', 'body',
        'featured_image_id', 'status', 'published_at', 'is_featured', 'allow_comments',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'draft',
        'is_featured' => false,
        'allow_comments' => true,
        'comment_count' => 0,
        'view_count' => 0,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => PageStatus::class,
            'published_at' => 'datetime',
            'is_featured' => 'boolean',
            'allow_comments' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $post): void {
            if (blank($post->slug)) {
                $post->slug = Str::slug($post->title);
            }

            $post->slug = Str::slug($post->slug);
            $post->reading_minutes = $post->estimateReadingMinutes();
        });
    }

    /** @return array<int, string> */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Reading time at roughly 200 words per minute, minimum one.
     *
     * A small courtesy that matters more than it looks on a slow connection,
     * where a visitor is deciding whether to spend the data at all.
     */
    public function estimateReadingMinutes(): int
    {
        $words = str_word_count(strip_tags((string) $this->body));

        return max(1, (int) ceil($words / 200));
    }

    // ── Relationships ────────────────────────────────────────────────────────

    /** @return BelongsTo<BlogCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(BlogCategory::class, 'blog_category_id');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /** @return BelongsTo<Media, $this> */
    public function featuredImage(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'featured_image_id');
    }

    /** @return HasMany<Comment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    /** Only comments a visitor may see. */
    public function approvedComments(): HasMany
    {
        return $this->comments()->where('status', Comment::STATUS_APPROVED)->whereNull('parent_id');
    }

    /** @return MorphToMany<Tag, $this> */
    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }

    // ── Publication — same rules as pages ────────────────────────────────────

    public function isLive(): bool
    {
        if (! $this->status->isPubliclyVisible()) {
            return false;
        }

        return $this->published_at === null || $this->published_at->isPast();
    }

    public function publish(?\DateTimeInterface $at = null): void
    {
        $at ??= now();

        $this->forceFill([
            'status' => $at > now() ? PageStatus::Scheduled : PageStatus::Published,
            'published_at' => $at,
        ])->save();
    }

    /**
     * Recount approved comments.
     *
     * The column is denormalised so an index listing does not run a COUNT per
     * row. Recomputed from the source rather than incremented, because a
     * moderator can approve, unapprove and delete in any order — and a counter
     * that drifts is worse than no counter, since nobody knows it is wrong.
     */
    public function refreshCommentCount(): void
    {
        static::whereKey($this->getKey())->update([
            'comment_count' => $this->comments()->where('status', Comment::STATUS_APPROVED)->count(),
        ]);
    }

    /** Whether the comment form should be shown at all. */
    public function acceptsComments(): bool
    {
        return $this->allow_comments
            && $this->isLive()
            && (bool) config('features.blog_comments', false);
    }

    #[Scope]
    protected function live(Builder $query): void
    {
        $query->whereIn('status', [PageStatus::Published, PageStatus::Scheduled])
            ->where(fn (Builder $q) => $q->whereNull('published_at')->orWhere('published_at', '<=', now()))
            ->orderByDesc('published_at');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['title', 'slug', 'status', 'published_at', 'blog_category_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('post');
    }
}
