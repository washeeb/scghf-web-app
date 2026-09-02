<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A downloadable document: annual report, policy, form, brochure.
 */
class Document extends Model
{
    use HasUlids;
    use SoftDeletes;

    protected $fillable = [
        'title', 'slug', 'description', 'media_id', 'document_type',
        'year', 'requires_auth', 'sort_order', 'is_published',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'document_type' => 'other',
        'requires_auth' => false,
        'sort_order' => 0,
        'is_published' => false,
        'download_count' => 0,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'requires_auth' => 'boolean',
            'is_published' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(fn (self $doc) => $doc->slug = Str::slug($doc->slug ?: $doc->title));
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

    /** @return BelongsTo<Media, $this> */
    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

    /**
     * Whether a visitor may download this.
     *
     * Restricted documents are served by an authorised controller, never by a
     * public storage URL, so this is the only gate that matters.
     */
    public function isDownloadableBy(?User $user): bool
    {
        if (! $this->is_published) {
            return false;
        }

        return ! $this->requires_auth || $user !== null;
    }

    public function recordDownload(): void
    {
        // Atomic. A read-modify-write loses counts the moment two people
        // download the annual report at the same time.
        static::whereKey($this->getKey())->update([
            'download_count' => DB::raw('download_count + 1'),
        ]);
    }

    #[Scope]
    protected function published(Builder $query): void
    {
        $query->where('is_published', true)->orderBy('sort_order');
    }

    #[Scope]
    protected function publiclyAvailable(Builder $query): void
    {
        $query->published()->where('requires_auth', false);
    }
}
