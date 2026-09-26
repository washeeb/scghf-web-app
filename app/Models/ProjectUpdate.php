<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Slug;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A dated entry on a project's timeline.
 *
 * Its own table rather than a blog post, because an update belongs to the
 * project's record and must appear there whether or not anyone remembered to
 * tag it. A donor following one project should not have to search the blog to
 * find out what happened to their money.
 */
class ProjectUpdate extends Model
{
    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    protected $fillable = [
        'project_id', 'title', 'slug', 'body', 'image_id',
        'author_id', 'is_published', 'published_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_published' => false,
    ];

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $update): void {
            $update->slug = Slug::for($update->slug, $update->title);
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

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /** @return BelongsTo<Media, $this> */
    public function image(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'image_id');
    }

    public function isLive(): bool
    {
        return $this->is_published
            && ($this->published_at === null || $this->published_at->isPast());
    }

    #[Scope]
    protected function live(Builder $query): void
    {
        $query->where('is_published', true)
            ->where(fn (Builder $q) => $q->whereNull('published_at')->orWhere('published_at', '<=', now()))
            ->orderByDesc('published_at');
    }
}
