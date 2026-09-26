<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Cause;
use App\Models\Document;
use App\Models\Event;
use App\Models\Faq;
use App\Models\FocusArea;
use App\Models\Gallery;
use App\Models\Page;
use App\Models\Post;
use App\Models\Product;
use App\Models\Project;
use App\Support\Features;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `/sitemap.xml` and `/robots.txt`.
 *
 * ── The sitemap toggle was doing nothing ────────────────────────────────────
 *
 * `pages.show_in_sitemap` has been a column since Phase 3 and a switch in the
 * page builder since Phase 5, and there was no sitemap for it to affect. An
 * editor could turn it off on a thank-you page and change nothing at all.
 *
 * ── Built rather than pulled from the package ───────────────────────────────
 *
 * `spatie/laravel-sitemap` is a dependency and its crawler is not what is
 * wanted here: it fetches every page over HTTP to discover them. On shared
 * hosting that is the site crawling itself, once a day, through the same PHP
 * workers serving donors. The content is already in the database with a
 * published flag and a modified date, so the sitemap is a query.
 *
 * ── Cached for an hour, not for ever ────────────────────────────────────────
 *
 * Long enough that a crawler hitting it repeatedly costs nothing; short enough
 * that a page published this morning is discoverable this morning. A sitemap
 * busted on every content save would be a cache invalidation spread across
 * eight models for a file fetched a few times a day.
 *
 * ── robots.txt is a route, not a file ───────────────────────────────────────
 *
 * `.env.example` has said since Phase 2 that the indexing switch "drives
 * robots.txt AND the X-Robots-Tag header". Neither was true: `public/robots.txt`
 * was a static file allowing everything, so a staging deployment invited every
 * crawler in regardless of the setting — and a staging site indexed beside the
 * real one splits its ranking and shows donors a test site.
 */
class SitemapController extends Controller
{
    /** An hour. See the note above. */
    private const CACHE_SECONDS = 3600;

    /** The types a crawler can ask for on their own. */
    public const TYPES = ['pages', 'posts', 'programmes', 'shop', 'events', 'indexes'];

    /**
     * The sitemap index: one entry per type, each with the newest lastmod
     * in it. A crawler that wants only the shop fetches only the shop.
     */
    public function sitemap(): Response
    {
        $entries = collect(self::TYPES)
            ->map(fn (string $type): array => ['type' => $type, 'urls' => $this->cached($type)])
            ->filter(fn (array $e): bool => $e['urls'] !== [])
            ->map(fn (array $e): array => [
                'loc' => route('sitemap.type', ['type' => $e['type']]),
                'lastmod' => collect($e['urls'])->pluck('lastmod')->filter()->max(),
            ])
            ->values()
            ->all();

        return $this->xml(view('sitemap-index', ['sitemaps' => $entries])->render());
    }

    /** One type's URLs. */
    public function type(string $type): Response
    {
        if (! in_array($type, self::TYPES, true)) {
            throw new NotFoundHttpException;
        }

        return $this->xml(view('sitemap', ['urls' => $this->cached($type)])->render());
    }

    /**
     * Regenerated on publish: `SitemapObserver` forgets these keys whenever
     * anything that can appear in a sitemap is saved or deleted, so the
     * next crawler fetch rebuilds it. Between saves it is served from cache.
     *
     * @return array<int, array{loc: string, lastmod: string|null, priority: string}>
     */
    private function cached(string $type): array
    {
        return cache()->remember(self::cacheKey($type), self::CACHE_SECONDS, fn (): array => $this->urlsFor($type)->values()->all());
    }

    public static function cacheKey(string $type): string
    {
        return 'sitemap.'.$type;
    }

    public static function forget(): void
    {
        foreach (self::TYPES as $type) {
            cache()->forget(self::cacheKey($type));
        }
    }

    /** @return Collection<int, array{loc: string, lastmod: string|null, priority: string}> */
    private function urlsFor(string $type): Collection
    {
        return match ($type) {
            'pages' => $this->pages(),
            'posts' => $this->posts(),
            'programmes' => $this->programmes(),
            'shop' => $this->shop(),
            'events' => $this->events(),
            'indexes' => $this->simpleIndexes(),
            default => collect(),
        };
    }

    private function xml(string $body): Response
    {
        return response($body, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age='.self::CACHE_SECONDS,
        ]);
    }

    /**
     * robots.txt, decided by the indexing setting.
     *
     * A blanket `Disallow: /` when indexing is off, because the meta tag only
     * protects HTML — a crawler fetching an uploaded PDF or an image never
     * parses one. The admin path is excluded either way: there is nothing to
     * index behind a login, and publishing the path is a small courtesy to
     * anybody scanning for admin panels.
     */
    public function robots(): Response
    {
        $allowed = (bool) setting('seo.allow_indexing', false);

        $lines = $allowed
            ? [
                'User-agent: *',
                'Disallow: /'.trim((string) config('admin.path', 'admin'), '/').'/',
                'Disallow: /account/',
                'Disallow: /search',
                ...array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string) setting('seo.robots_extra', '')) ?: []))),
                '',
                'Sitemap: '.route('sitemap'),
            ]
            : [
                '# Indexing is switched off for this installation.',
                'User-agent: *',
                'Disallow: /',
            ];

        return response(implode("\n", $lines)."\n", 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'public, max-age='.self::CACHE_SECONDS,
        ]);
    }

    /**
     * ⚠ Only what is genuinely public. A sitemap is a list handed to crawlers,
     * so an unpublished draft or a page an editor excluded must not appear —
     * and a document marked `requires_auth` least of all, since listing it
     * advertises the existence of an internal policy to everybody.
     *
     * @return Collection<int, array{loc: string, lastmod: string|null, priority: string}>
     */
    private function pages(): Collection
    {
        $home = collect([[
            'loc' => url('/'),
            'lastmod' => null,
            'priority' => '1.0',
        ]]);

        return $home->concat(Page::query()
            ->with('seo')
            ->where('show_in_sitemap', true)
            ->where('is_homepage', false)
            ->get()
            ->filter(fn (Page $page): bool => $page->isLive() && $page->seoShouldIndex())
            ->map(fn (Page $page): array => [
                'loc' => url($page->path),
                'lastmod' => $page->updated_at?->toAtomString(),
                'priority' => '0.8',
            ]));
    }

    /** @return Collection<int, array{loc: string, lastmod: string|null, priority: string}> */
    private function posts(): Collection
    {
        return Post::query()
            ->with('seo')
            ->get()
            ->filter(fn (Post $post): bool => $post->isLive() && $post->seoShouldIndex())
            ->map(fn (Post $post): array => [
                'loc' => route('news.show', $post),
                'lastmod' => $post->updated_at?->toAtomString(),
                'priority' => '0.6',
            ]);
    }

    /**
     * Areas of work, projects and appeals — the pages a donor is most likely
     * to search for.
     *
     * @return Collection<int, array{loc: string, lastmod: string|null, priority: string}>
     */
    private function programmes(): Collection
    {
        $entries = collect();

        if (Route::has('focus-areas.show')) {
            $entries = $entries->concat(FocusArea::query()->active()->get()
                ->map(fn (FocusArea $area): array => ['loc' => route('focus-areas.show', $area), 'lastmod' => $area->updated_at?->toAtomString(), 'priority' => '0.7']));
        }

        if (Route::has('projects.show')) {
            $entries = $entries->concat(Project::query()->with('seo')->get()->filter(fn (Project $p): bool => $p->isLive() && $p->seoShouldIndex())
                ->map(fn (Project $p): array => ['loc' => route('projects.show', $p), 'lastmod' => $p->updated_at?->toAtomString(), 'priority' => '0.7']));
        }

        if (Route::has('causes.show')) {
            $entries = $entries->concat(Cause::query()->with('seo')->get()->filter(fn (Cause $c): bool => $c->isLive() && $c->seoShouldIndex())
                ->map(fn (Cause $c): array => ['loc' => route('causes.show', $c), 'lastmod' => $c->updated_at?->toAtomString(), 'priority' => '0.8']));
        }

        return $entries;
    }

    /** @return Collection<int, array{loc: string, lastmod: string|null, priority: string}> */
    private function shop(): Collection
    {
        if (! Route::has('shop.show') || ! app(Features::class)->enabled('shop')) {
            return collect();
        }

        return Product::query()->with('seo')->get()->filter(fn (Product $p): bool => $p->isLive() && $p->seoShouldIndex())
            ->map(fn (Product $p): array => ['loc' => route('shop.show', $p), 'lastmod' => $p->updated_at?->toAtomString(), 'priority' => '0.5']);
    }

    /** @return Collection<int, array{loc: string, lastmod: string|null, priority: string}> */
    private function events(): Collection
    {
        if (! Route::has('events.show') || ! app(Features::class)->enabled('events')) {
            return collect();
        }

        return Event::query()->with('seo')->get()->filter(fn (Event $e): bool => $e->isLive() && $e->seoShouldIndex())
            ->map(fn (Event $e): array => ['loc' => route('events.show', $e), 'lastmod' => $e->updated_at?->toAtomString(), 'priority' => '0.6']);
    }

    /**
     * The index pages that exist because their content does.
     *
     * Listed only when there is something on them. A sitemap entry for an empty
     * FAQ page is a crawler sent to a page saying "nothing here yet", which is
     * the first impression it then keeps.
     *
     * @return Collection<int, array{loc: string, lastmod: null, priority: string}>
     */
    private function simpleIndexes(): Collection
    {
        return collect([
            ['route' => 'news.index', 'has' => Post::query()->where('status', 'published')->exists()],
            ['route' => 'faq', 'has' => Faq::query()->where('is_published', true)->exists()],
            ['route' => 'galleries.index', 'has' => Gallery::query()->where('is_published', true)->exists()],
            ['route' => 'documents.index', 'has' => Document::query()->where('is_published', true)->where('requires_auth', false)->exists()],
            ['route' => 'contact', 'has' => true],
            ['route' => 'donate', 'has' => true],
            ['route' => 'give', 'has' => true],
            ['route' => 'impact', 'has' => true],
            ['route' => 'focus-areas.index', 'has' => FocusArea::query()->where('is_active', true)->exists()],
            ['route' => 'projects.index', 'has' => Project::query()->where('is_published', true)->exists()],
            ['route' => 'causes.index', 'has' => Cause::query()->where('is_published', true)->exists()],
            ['route' => 'shop.index', 'has' => app(Features::class)->enabled('shop') && Product::query()->where('is_published', true)->exists()],
            ['route' => 'events.index', 'has' => app(Features::class)->enabled('events') && Event::query()->where('is_published', true)->exists()],
        ])
            ->filter(fn (array $index): bool => $index['has'] && Route::has($index['route']))
            ->map(fn (array $index): array => [
                'loc' => route($index['route']),
                'lastmod' => null,
                'priority' => '0.5',
            ]);
    }
}
