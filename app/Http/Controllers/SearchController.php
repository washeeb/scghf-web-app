<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PageStatus;
use App\Models\Document;
use App\Models\Faq;
use App\Models\Page;
use App\Models\Post;
use App\Support\PageMeta;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Searching the public site.
 *
 * ── LIKE, not a search engine ───────────────────────────────────────────────
 *
 * No Scout, no Meilisearch, no Elasticsearch. All three need a daemon this host
 * does not run, and MySQL full-text on InnoDB has a default minimum word length
 * that silently drops three-letter words — which on this site means "aid".
 *
 * A `LIKE '%term%'` across a few hundred rows of a foundation's website is a
 * few milliseconds, and it behaves the way a visitor expects: it matches the
 * middle of words, which is what somebody typing "borehole" into a site with
 * "boreholes" in every heading actually needs.
 *
 * If the content ever outgrows that, `FEATURE_SITE_SEARCH` is the flag to hang
 * a real index off — and it is currently OFF, which is why this page is a
 * simple one rather than a promise of something better.
 *
 * ── Only what a visitor could already reach ─────────────────────────────────
 *
 * Every source is filtered to published, and pages additionally to
 * `show_in_search`. A search box that surfaces a draft is a way to read
 * unpublished content by guessing words in it.
 *
 * ── The results page is noindexed ───────────────────────────────────────────
 *
 * Search result pages are near-duplicates of each other and of the pages they
 * link to. Letting a crawler index them spends the site's crawl budget on
 * combinations of query strings.
 */
class SearchController extends Controller
{
    /** Beyond this a visitor is better served by narrowing the term. */
    private const PER_TYPE = 10;

    public function __invoke(Request $request): View
    {
        $term = trim((string) $request->query('q', ''));

        return view('search', [
            'term' => $term,
            // Two characters is where a search stops being a search: "a"
            // matches everything and tells nobody anything.
            'results' => mb_strlen($term) < 2 ? collect() : $this->search($term),
            'meta' => PageMeta::site(
                $term === ''
                    ? __('Search')
                    : __('Search: :term', ['term' => $term]),
                noindex: true,
            ),
            'crumbs' => [
                ['label' => __('Home'), 'url' => url('/')],
                ['label' => __('Search'), 'url' => null],
            ],
        ]);
    }

    /** @return Collection<int, array{title: string, url: string, summary: ?string, kind: string}> */
    private function search(string $term): Collection
    {
        return collect()
            ->concat($this->pages($term))
            ->concat($this->posts($term))
            ->concat($this->faqs($term))
            ->concat($this->documents($term));
    }

    /** @return Collection<int, array<string, mixed>> */
    private function pages(string $term): Collection
    {
        return Page::query()
            ->where('show_in_search', true)
            ->where(fn (Builder $q) => $this->match($q, $term, ['title', 'excerpt']))
            ->limit(self::PER_TYPE)
            ->get()
            ->filter(fn (Page $page): bool => $page->isLive())
            ->map(fn (Page $page): array => [
                'title' => $page->title,
                'url' => url($page->path),
                'summary' => $page->excerpt,
                'kind' => __('Page'),
            ]);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function posts(string $term): Collection
    {
        return Post::query()
            ->whereIn('status', [PageStatus::Published->value, PageStatus::Scheduled->value])
            ->where(fn (Builder $q) => $q->whereNull('published_at')->orWhere('published_at', '<=', now()))
            ->where(fn (Builder $q) => $this->match($q, $term, ['title', 'excerpt', 'body']))
            ->latest('published_at')
            ->limit(self::PER_TYPE)
            ->get()
            ->map(fn (Post $post): array => [
                'title' => $post->title,
                'url' => route('news.show', $post),
                'summary' => $post->excerpt,
                'kind' => __('News'),
            ]);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function faqs(string $term): Collection
    {
        return Faq::query()
            ->where('is_published', true)
            ->where(fn (Builder $q) => $this->match($q, $term, ['question', 'answer']))
            ->limit(self::PER_TYPE)
            ->get()
            ->map(fn (Faq $faq): array => [
                'title' => $faq->question,
                // Straight to the question on the FAQ page. A result that lands
                // somebody at the top of a page of forty questions has made
                // them search twice.
                'url' => route('faq').'#faq-'.$faq->getKey(),
                'summary' => str((string) $faq->answer)->stripTags()->squish()->limit(160)->toString(),
                'kind' => __('FAQ'),
            ]);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function documents(string $term): Collection
    {
        return Document::query()
            ->where('is_published', true)
            ->where('requires_auth', false)
            ->where(fn (Builder $q) => $this->match($q, $term, ['title', 'description']))
            ->limit(self::PER_TYPE)
            ->get()
            ->map(fn (Document $document): array => [
                'title' => $document->title,
                'url' => route('documents.index').'#document-'.$document->getKey(),
                'summary' => $document->description,
                'kind' => __('Report'),
            ]);
    }

    /**
     * `LIKE %term%` across several columns.
     *
     * The term is escaped for LIKE's own wildcards before it goes near the
     * query. Without that, a visitor searching for `100%` gets every row on the
     * site — the `%` is a wildcard, not a character — and one searching for `_`
     * matches everything too. Bindings stop injection; they do not stop this.
     *
     * @param  array<int, string>  $columns
     */
    private function match(Builder $query, string $term, array $columns): Builder
    {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term);

        foreach ($columns as $index => $column) {
            $query->{$index === 0 ? 'where' : 'orWhere'}($column, 'like', '%'.$escaped.'%');
        }

        return $query;
    }
}
