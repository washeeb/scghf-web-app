<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PageStatus;
use App\Models\BlogCategory;
use App\Models\Post;
use App\Support\PageMeta;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * News and stories.
 *
 * ── Scheduled posts are handled in the query, not by a job ──────────────────
 *
 * A post is live when its status allows it AND its publish date has passed.
 * That is a `where`, evaluated on every request, so a post scheduled for 6am
 * appears at 6am without anything needing to have run — which matters on a host
 * where the cron line is the thing most likely to be missing.
 *
 * ── A draft is a 404, not a 403 ─────────────────────────────────────────────
 *
 * Same rule as CMS pages. A 403 confirms something is there, and the address of
 * an unannounced appeal is worth guessing.
 */
class NewsController extends Controller
{
    /** Enough to fill a screen without making a phone download forty. */
    private const PER_PAGE = 9;

    public function index(): View
    {
        return view('news.index', [
            'posts' => $this->livePosts()->paginate(self::PER_PAGE),
            'categories' => $this->categories(),
            'category' => null,
            'meta' => PageMeta::site(
                __('News'),
                __('Updates from :name.', ['name' => setting('general.short_name', config('app.name'))]),
            ),
            'crumbs' => [
                ['label' => __('Home'), 'url' => url('/')],
                ['label' => __('News'), 'url' => null],
            ],
        ]);
    }

    public function category(BlogCategory $category): View
    {
        if (! $category->is_published) {
            throw new NotFoundHttpException;
        }

        return view('news.index', [
            'posts' => $this->livePosts()
                ->where('blog_category_id', $category->getKey())
                ->paginate(self::PER_PAGE),
            'categories' => $this->categories(),
            'category' => $category,
            'meta' => PageMeta::for($category),
            'crumbs' => [
                ['label' => __('Home'), 'url' => url('/')],
                ['label' => __('News'), 'url' => route('news.index')],
                ['label' => $category->name, 'url' => null],
            ],
        ]);
    }

    public function show(Post $post): View
    {
        if (! $post->isLive()) {
            throw new NotFoundHttpException;
        }

        $post->loadMissing(['category', 'author', 'featuredImage', 'tags']);

        /*
         * Counted here rather than on the queue.
         *
         * `view_count` is a rough popularity signal shown in the admin, not an
         * analytics figure — the visitor statistics are aggregate by
         * construction and live elsewhere. One atomic UPDATE is cheaper than
         * dispatching a job on a host whose queue runs once a minute.
         */
        Post::whereKey($post->getKey())->increment('view_count');

        $meta = PageMeta::for($post);

        return view('news.show', [
            'post' => $post,
            'related' => $this->related($post),
            'meta' => $meta->with([
                'canonical' => $post->seo?->canonical_url ?: route('news.show', $post),
                'imageUrl' => $post->featuredImage?->isPublishable()
                    ? $post->featuredImage->conversionUrl('card')
                    : $meta->imageUrl,
                'imageAlt' => $post->featuredImage?->altText() ?? $meta->imageAlt,
                // `article`, not `website`. It is what puts the byline and the
                // date on a shared link rather than a generic site card.
                'type' => 'article',
                'author' => $post->author?->name,
            ]),
            'crumbs' => array_values(array_filter([
                ['label' => __('Home'), 'url' => url('/')],
                ['label' => __('News'), 'url' => route('news.index')],
                $post->category
                    ? ['label' => $post->category->name, 'url' => route('news.category', $post->category)]
                    : null,
                ['label' => $post->title, 'url' => null],
            ])),
        ]);
    }

    /**
     * Posts a reader might want next.
     *
     * Same category first, then anything recent — because "related posts" that
     * are empty on a site with four articles is worse than a slightly loose
     * match, and a foundation's news section is small for a long time.
     */
    private function related(Post $post): Collection
    {
        $query = $this->livePosts()->whereKeyNot($post->getKey());

        $sameCategory = $post->blog_category_id === null
            ? collect()
            : (clone $query)->where('blog_category_id', $post->blog_category_id)->limit(3)->get();

        if ($sameCategory->count() >= 3) {
            return $sameCategory;
        }

        return $sameCategory->concat(
            $query->whereNotIn('posts.id', $sameCategory->pluck('id')->all())
                ->limit(3 - $sameCategory->count())
                ->get()
        );
    }

    /** @return Builder<Post> */
    private function livePosts(): Builder
    {
        return Post::query()
            ->whereIn('status', [PageStatus::Published->value, PageStatus::Scheduled->value])
            ->where(fn (Builder $q) => $q->whereNull('published_at')->orWhere('published_at', '<=', now()))
            ->with(['category', 'author', 'featuredImage'])
            ->latest('published_at');
    }

    /** Only categories with something live in them. */
    private function categories(): Collection
    {
        return BlogCategory::query()
            ->where('is_published', true)
            ->whereHas('posts', fn (Builder $q) => $q
                ->whereIn('status', [PageStatus::Published->value, PageStatus::Scheduled->value])
                ->where(fn (Builder $inner) => $inner->whereNull('published_at')->orWhere('published_at', '<=', now())))
            ->orderBy('sort_order')
            ->get();
    }
}
