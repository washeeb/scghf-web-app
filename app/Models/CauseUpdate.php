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
 * A dated entry on an appeal.
 *
 * What donors to this specific appeal are told happened with their money. Kept
 * separate from project updates because the audiences differ: a project update
 * reports the work, an appeal update reports the money.
 */
class CauseUpdate extends Model
{
    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    protected $fillable = [
        'cause_id', 'title', 'slug', 'body', 'image_id',
        'author_id', 'is_published', 'published_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_published' => false,
    ];

    /** @return array<string, string> */
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

    /** @return BelongsTo<Cause, $this> */
    public function cause(): BelongsTo
    {
        return $this->belongsTo(Cause::class);
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
