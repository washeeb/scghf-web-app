<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PageStatus;
use App\Models\Concerns\HasSeo;
use App\Models\Concerns\RecordsAuthor;
use App\Support\SiteCache;
use App\Support\Slug;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * @property int $id
 * @property string $slug
 * @property string $path
 * @property PageStatus $status
 * @property bool $is_homepage
 * @property bool $is_locked
 */
class Page extends Model
{
    use HasFactory;
    use HasSeo;
    use HasUlids;
    use LogsActivity;
    use RecordsAuthor;
    use SoftDeletes;

    protected $fillable = [
        'parent_id', 'title', 'slug', 'excerpt', 'template',
        'status', 'published_at', 'show_in_sitemap', 'show_in_search', 'sort_order',
        // `path`, `is_homepage` and `is_locked` are deliberately absent:
        // path is derived, and the other two are structural decisions rather
        // than form fields. See setAsHomepage() and the booted() hook.
    ];

    /**
     * Defaults mirroring the migration's column defaults.
     *
     * Without these, a freshly created model has these attributes as NULL in
     * memory even though the database will store a default — so `isLive()`
     * calls a method on a null status, and a revision snapshot records nulls
     * that violate NOT NULL when restored. A model default and a column default
     * must always be declared together.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
        'template' => 'default',
        'sort_order' => 0,
        'is_homepage' => false,
        'is_locked' => false,
        'show_in_sitemap' => true,
        'show_in_search' => true,
    ];

    protected function casts(): array
    {
        return [
            'status' => PageStatus::class,
            'published_at' => 'datetime',
            'is_homepage' => 'boolean',
            'is_locked' => 'boolean',
            'show_in_sitemap' => 'boolean',
            'show_in_search' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $page): void {
            $page->slug = Slug::for($page->slug, $page->title);
            $page->path = $page->buildPath();
        });

        /*
         * Moving a parent has to move its whole subtree. Without this a child
         * keeps a path pointing at where its parent used to be — the page still
         * renders, but at an address no menu links to.
         *
         * `path` is in the watched list, not just slug and parent_id. A
         * grandchild's slug does not change when its grandparent is renamed —
         * only its path does — so watching slug alone cascaded exactly one
         * level and left everything deeper stranded.
         */
        static::saved(function (self $page): void {
            if ($page->wasChanged(['slug', 'parent_id', 'path'])) {
                $page->children()->each(fn (self $child) => $child->save());
            }
        });

        static::deleting(function (self $page): void {
            if ($page->is_locked && ! $page->isForceDeleting()) {
                throw new \RuntimeException(
                    "'{$page->title}' is a system page. The application routes to it by name, "
                    .'so deleting it would break navigation and payment callbacks. '
                    .'Unpublish it instead.'
                );
            }
        });
    }

    /** Pages resolve by their full path, so `/about/leadership` is one lookup. */
    public function getRouteKeyName(): string
    {
        return 'path';
    }

    /** @return array<int, string> */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    /**
     * The materialised path.
     *
     * The homepage is always '/' regardless of its slug, so the site root does
     * not depend on someone not renaming it.
     */
    public function buildPath(): string
    {
        if ($this->is_homepage) {
            return '/';
        }

        $segments = [$this->slug];
        $parent = $this->parent_id ? static::find($this->parent_id) : null;
        $guard = 0;

        // The guard is not paranoia: an admin can set A's parent to B while B's
        // parent is A, and without it this loops until the request times out.
        while ($parent !== null && $guard++ < 10) {
            array_unshift($segments, $parent->slug);
            $parent = $parent->parent_id ? static::find($parent->parent_id) : null;
        }

        return '/'.implode('/', array_filter($segments));
    }

    // ── Relationships ────────────────────────────────────────────────────────

    /**
     * The chain above this page, root first, for a breadcrumb.
     *
     * Walked one parent at a time. Pages nest two or three deep at most, and
     * the parents are usually already loaded; a recursive query would be
     * machinery for a problem the sitemap does not have.
     *
     * @return array<int, Page>
     */
    public function ancestors(): array
    {
        $chain = [];
        $page = $this->parent;

        while ($page !== null && count($chain) < 10) {
            array_unshift($chain, $page);
            $page = $page->parent;
        }

        return $chain;
    }

    /** @return BelongsTo<Page, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<Page, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order');
    }

    /** @return HasMany<PageSection, $this> */
    public function sections(): HasMany
    {
        return $this->hasMany(PageSection::class)->orderBy('sort_order');
    }

    /** @return HasMany<PageRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(PageRevision::class)->latest('revision_number');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    // ── Publication ──────────────────────────────────────────────────────────

    /**
     * Whether the public may see this page right now.
     *
     * A scheduled page becomes visible the moment its date passes, with nobody
     * touching it — which is the whole point of scheduling.
     */
    /**
     * The public URL of a live top-level page by slug, or null.
     *
     * For the layout's policy links — privacy, cookies, terms — which every
     * page renders and which are three queries nobody notices until a
     * shared host bills for them. Cached under the site generation, so
     * publishing the page makes the link appear on the next request.
     */
    public static function liveUrl(string $slug): ?string
    {
        $path = SiteCache::remember('page-url:'.$slug, function () use ($slug): string {
            $page = static::query()->where('slug', $slug)->whereNull('parent_id')->first();

            // '' rather than null: a cache cannot tell "absent" from "nothing yet".
            return $page?->isLive() ? (string) $page->path : '';
        });

        return $path === '' ? null : url($path);
    }

    /** The live top-level page itself, for a link that needs its title too. */
    public static function liveBySlug(string $slug): ?self
    {
        return SiteCache::remember('page:'.$slug, function () use ($slug): ?self {
            $page = static::query()->where('slug', $slug)->whereNull('parent_id')->first();

            return $page?->isLive() ? $page : null;
        });
    }

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

    public function unpublish(): void
    {
        $this->forceFill(['status' => PageStatus::Draft])->save();
    }

    /**
     * Make this the site root.
     *
     * Demotes whatever held it first, in a transaction, because two homepages
     * means '/' resolves to whichever the database happens to return.
     */
    public function setAsHomepage(): void
    {
        \DB::transaction(function (): void {
            static::where('is_homepage', true)
                ->whereKeyNot($this->getKey())
                ->each(function (self $page): void {
                    $page->forceFill(['is_homepage' => false])->save();
                });

            $this->forceFill(['is_homepage' => true])->save();
        });
    }

    // ── Revisions ────────────────────────────────────────────────────────────

    /**
     * Snapshot this page and its blocks as they are now.
     *
     * Called BEFORE a save, so a revision records the state you can return to.
     */
    public function snapshot(?string $summary = null, ?User $user = null): PageRevision
    {
        return $this->revisions()->create([
            'user_id' => $user?->getKey(),
            'revision_number' => ($this->revisions()->max('revision_number') ?? 0) + 1,
            'summary' => $summary,
            'snapshot' => json_encode([
                'page' => $this->only([
                    'title', 'slug', 'excerpt', 'template', 'status',
                    'published_at', 'parent_id', 'sort_order',
                ]),
                /*
                 * ⚠ EVERY column a restore has to put back.
                 *
                 * A snapshot that omits a column is a restore that silently
                 * clears it — the page comes back looking restored, and the
                 * missing part is only noticed by whoever set it. `settings`,
                 * `visible_from` and `visible_until` were absent when the
                 * presentation and scheduling columns were added, which would
                 * have reset every block on a page to the site defaults on the
                 * first restore anybody performed.
                 *
                 * Anything added to `page_sections` that an editor can set
                 * belongs in this list on the same day it is added.
                 */
                'sections' => $this->sections()->get()
                    ->map(fn (PageSection $s): array => $s->only([
                        'block_type', 'name', 'data', 'settings', 'sort_order',
                        'is_visible', 'visible_from', 'visible_until',
                    ]))
                    ->all(),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
        ]);
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    /** Everything the public may see. The only scope a front-end query should use. */
    #[Scope]
    protected function live(Builder $query): void
    {
        $query->whereIn('status', [PageStatus::Published, PageStatus::Scheduled])
            ->where(function (Builder $q): void {
                $q->whereNull('published_at')->orWhere('published_at', '<=', now());
            });
    }

    #[Scope]
    protected function topLevel(Builder $query): void
    {
        $query->whereNull('parent_id')->orderBy('sort_order');
    }

    #[Scope]
    protected function inSitemap(Builder $query): void
    {
        $query->live()->where('show_in_sitemap', true);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['title', 'slug', 'path', 'status', 'published_at', 'parent_id', 'template'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('page');
    }
}
