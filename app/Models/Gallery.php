<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToDivision;
use App\Models\Concerns\RecordsAuthor;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use RuntimeException;

class Gallery extends Model
{
    use BelongsToDivision;
    use HasUlids;
    use RecordsAuthor;
    use SoftDeletes;

    protected $fillable = [
        'division_id', 'title', 'slug', 'description', 'cover_id', 'taken_on', 'location',
        'has_consent', 'sort_order', 'is_published',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'sort_order' => 0,
        'has_consent' => false,
        'is_published' => false,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'has_consent' => 'boolean',
            'is_published' => 'boolean',
            'taken_on' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $gallery): void {
            $gallery->slug = Str::slug($gallery->slug ?: $gallery->title);

            // The same gate as testimonials. A gallery is where photographs of
            // children are most likely to end up, so publication without a
            // recorded consent is blocked in the model rather than left to an
            // admin remembering.
            if ($gallery->is_published && ! $gallery->has_consent) {
                throw new RuntimeException(
                    'This gallery cannot be published without recorded consent for the people in it.'
                );
            }
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

    /** @return HasMany<GalleryItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(GalleryItem::class)->orderBy('sort_order');
    }

    /** @return BelongsTo<Media, $this> */
    public function cover(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'cover_id');
    }

    #[Scope]
    protected function published(Builder $query): void
    {
        $query->where('is_published', true)->orderBy('sort_order');
    }
}
