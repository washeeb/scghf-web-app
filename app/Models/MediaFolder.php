<?php

declare(strict_types=1);

namespace App\Models;

use App\Media\Concerns\HasLibraryMedia;
use App\Models\Concerns\RecordsAuthor;
use App\Support\Slug;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;
use Spatie\MediaLibrary\HasMedia;

/**
 * A folder in the media library.
 *
 * @property string $path
 */
class MediaFolder extends Model implements HasMedia
{
    /*
     * A folder OWNS the files filed under it, in spatie's sense of the word.
     *
     * That is not a modelling flourish — spatie resolves which conversions to
     * generate by asking `media.model_type` for them, so a file with no owner
     * gets no thumbnail and no card. A central library whose files belong to
     * nothing would be a library with no conversions at all, and the folder is
     * the thing every library file already belongs to.
     *
     * `HasLibraryMedia` carries the three standard conversions and, more
     * importantly, the rule that an unsanitised image gets none of them.
     */
    use HasLibraryMedia;
    use HasUlids;
    use RecordsAuthor;

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
            $folder->slug = Slug::for($folder->slug, $folder->name);

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
