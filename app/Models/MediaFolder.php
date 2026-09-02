<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A folder in the media library.
 *
 * @property string $path
 */
class MediaFolder extends Model
{
    use HasUlids;

    protected $fillable = ['parent_id', 'name', 'slug', 'description', 'sort_order'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'sort_order' => 0,
        'is_locked' => false,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_locked' => 'boolean'];
    }

    protected static function booted(): void
    {
        // Same materialised-path approach as pages, for the same reason: a
        // unique index on (parent_id, slug) would allow two root folders with
        // the same name, because MySQL permits many NULLs in a unique index.
        static::saving(function (self $folder): void {
            if (blank($folder->slug)) {
                $folder->slug = Str::slug($folder->name);
            }

            $segments = [$folder->slug];
            $parent = $folder->parent_id ? static::find($folder->parent_id) : null;
            $guard = 0;

            while ($parent !== null && $guard++ < 10) {
                array_unshift($segments, $parent->slug);
                $parent = $parent->parent_id ? static::find($parent->parent_id) : null;
            }

            $folder->path = '/'.implode('/', array_filter($segments));
        });

        static::saved(function (self $folder): void {
            if ($folder->wasChanged(['slug', 'parent_id', 'path'])) {
                $folder->children()->each(fn (self $child) => $child->save());
            }
        });

        static::deleting(function (self $folder): void {
            if ($folder->is_locked) {
                throw new RuntimeException(
                    "The [{$folder->name}] folder is used by the application. "
                    .'Beneficiary media in particular is subject to consent rules '
                    .'that are keyed to this folder.'
                );
            }
        });
    }

    /** @return array<int, string> */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    /** @return BelongsTo<MediaFolder, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<MediaFolder, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order');
    }
}
