<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToDivision;
use App\Models\Concerns\RecordsAuthor;
use App\Support\SiteCache;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;
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
    use RecordsAuthor;

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

    /**
     * The one notice to draw for this request, or none.
     *
     * ── Dismissal is checked here, not in the view ──────────────────────────
     *
     * A visitor who has closed a notice should not have it counted as seen
     * again on every page they visit afterwards. Filtering in the view would
     * mean the impression was already recorded by then, and the click-through
     * rate the admin screen reports would be measured against a number that
     * grows for people who are not being shown anything.
     *
     * ── Lowest sort order wins ──────────────────────────────────────────────
     *
     * Two live notices with the same placement is an ordinary situation — a
     * standing appeal and an office-closure notice — and the bar can only draw
     * one. `sort_order` is how the foundation says which, and `live()` already
     * orders by it.
     */
    public static function forRequest(string $placement, Request $request): ?self
    {
        // The live list is cached; which one applies to this path and this
        // visitor's dismissals is decided per request, in memory. The
        // "live" window is a time range, so the fragment TTL bounds how late
        // a scheduled start or end can be seen: ten minutes.
        $rows = SiteCache::remember('announcements:'.$placement, fn (): array => static::query()
            ->live()
            ->placement($placement)
            ->get()
            ->map(fn (self $a): array => $a->getAttributes())
            ->all(), 600);

        return static::hydrate($rows)->first(fn (self $announcement): bool => $announcement->appliesTo($request->path())
            && ! $announcement->wasDismissedBy($request));
    }

    /**
     * Whether this visitor has already closed this notice.
     *
     * One cookie per notice, keyed by ulid rather than id: the cookie name is
     * visible in the browser, and a sequential id there tells anybody looking
     * how many notices the foundation has ever published.
     */
    public function wasDismissedBy(Request $request): bool
    {
        return $this->is_dismissible && $request->cookie($this->dismissalCookieName()) !== null;
    }

    public function dismissalCookieName(): string
    {
        return 'scghf_dismissed_'.$this->ulid;
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
