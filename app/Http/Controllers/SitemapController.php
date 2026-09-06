<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\Faq;
use App\Models\Gallery;
use App\Models\Page;
use App\Models\Post;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

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

    public function sitemap(): Response
    {
        $urls = cache()->remember('sitemap.urls', self::CACHE_SECONDS, fn (): array => $this->urls()->all());

        $xml = view('sitemap', ['urls' => $urls])->render();

        return response($xml, 200, [
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
     * Everything worth listing.
     *
     * ⚠ Only what is genuinely public. A sitemap is a list handed to crawlers,
     * so an unpublished draft or a page an editor excluded must not appear —
     * and a document marked `requires_auth` least of all, since listing it
     * advertises the existence of an internal policy to everybody.
     *
     * @return Collection<int, array{loc: string, lastmod: string|null, priority: string}>
     */
    private function urls(): Collection
    {
        $urls = collect([[
            'loc' => url('/'),
            'lastmod' => null,
            'priority' => '1.0',
        ]]);

        $pages = Page::query()
            ->where('show_in_sitemap', true)
            ->where('is_homepage', false)
            ->get()
            ->filter(fn (Page $page): bool => $page->isLive() && $page->seoShouldIndex())
            ->map(fn (Page $page): array => [
                'loc' => url($page->path),
                'lastmod' => $page->updated_at?->toAtomString(),
                'priority' => '0.8',
            ]);

        $posts = Post::query()
            ->get()
            ->filter(fn (Post $post): bool => $post->isLive())
            ->map(fn (Post $post): array => [
                'loc' => route('news.show', $post),
                'lastmod' => $post->updated_at?->toAtomString(),
                'priority' => '0.6',
            ]);

        return $urls
            ->concat($pages)
            ->concat($posts)
            ->concat($this->simpleIndexes())
            ->values();
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
        ])
            ->filter(fn (array $index): bool => $index['has'] && Route::has($index['route']))
            ->map(fn (array $index): array => [
                'loc' => route($index['route']),
                'lastmod' => null,
                'priority' => '0.5',
            ]);
    }
}
