<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToDivision;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * A banner, announcement bar or popup.
 *
 * One table for all three because they differ only in where they render, and
 * three near-identical tables would mean three admin screens for what staff
 * think of as one job.
 */
class Announcement extends Model
{
    use BelongsToDivision;
    use HasUlids;

    protected $fillable = [
        'division_id', 'placement', 'title', 'body', 'cta_label', 'cta_url', 'image_id',
        'style', 'is_dismissible', 'dismiss_days', 'show_on_paths',
        'starts_at', 'ends_at', 'sort_order', 'is_active',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'placement' => 'announcement_bar',
        'style' => 'info',
        'is_dismissible' => true,
        'dismiss_days' => 30,
        'sort_order' => 0,
        'is_active' => false,
        'impressions' => 0,
        'clicks' => 0,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'show_on_paths' => 'array',
            'is_dismissible' => 'boolean',
            'is_active' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /** @return array<int, string> */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    /** @return BelongsTo<Media, $this> */
    public function image(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'image_id');
    }

    /** Whether this is within its scheduled window right now. */
    public function isLive(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->starts_at !== null && $this->starts_at->isFuture()) {
            return false;
        }

        return $this->ends_at === null || $this->ends_at->isFuture();
    }

    /**
     * Whether it should show on a given path.
     *
     * An empty path list means everywhere. Entries may end in `*` to match a
     * section, so `/projects*` covers the index and every project.
     */
    public function appliesTo(string $path): bool
    {
        $paths = $this->show_on_paths;

        if (blank($paths)) {
            return true;
        }

        foreach ($paths as $pattern) {
            if (str_ends_with($pattern, '*')) {
                if (str_starts_with($path, rtrim($pattern, '*'))) {
                    return true;
                }
            } elseif ($path === $pattern) {
                return true;
            }
        }

        return false;
    }

    public function recordImpression(): void
    {
        static::whereKey($this->getKey())->update(['impressions' => DB::raw('impressions + 1')]);
    }

    public function recordClick(): void
    {
        static::whereKey($this->getKey())->update(['clicks' => DB::raw('clicks + 1')]);
    }

    /** Click-through rate as a percentage, for the admin listing. */
    public function clickThroughRate(): float
    {
        return $this->impressions === 0
            ? 0.0
            : round(($this->clicks / $this->impressions) * 100, 2);
    }

    #[Scope]
    protected function live(Builder $query): void
    {
        $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->orderBy('sort_order');
    }

    #[Scope]
    protected function placement(Builder $query, string $placement): void
    {
        $query->where('placement', $placement);
    }
}
