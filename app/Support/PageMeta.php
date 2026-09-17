<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Media;
use Illuminate\Contracts\Support\Arrayable;

/**
 * Everything that goes in a page's `<head>`, resolved once.
 *
 * ── Why this exists ─────────────────────────────────────────────────────────
 *
 * `HasSeo::seoOpenGraph()` was written in Phase 3 and read by nothing. The
 * layout emitted a title, a description and sometimes a noindex, and that was
 * all — no canonical, no Open Graph, no Twitter card. Which means that until
 * now, every time somebody shared a cause on WhatsApp (the way most of this
 * foundation's supporters share anything), it rendered as a bare blue link with
 * no title, no summary and no picture.
 *
 * A donation appeal shared as a bare link is an appeal nobody clicks.
 *
 * ── The canonical is not decoration either ──────────────────────────────────
 *
 * The same page is reachable with a trailing slash, with a `?utm_source`, and
 * through a redirect. Without a canonical, a search engine treats those as
 * separate pages competing with each other, and the foundation's own newsletter
 * campaign quietly outranks the page it points at.
 *
 * ── One object, not eight component parameters ──────────────────────────────
 *
 * The alternative is a layout with `title`, `description`, `noindex`,
 * `canonical`, `ogImage`, `ogType`, `publishedAt` and `author` slots, and every
 * caller remembering all eight. This is built by whoever has the record and
 * handed over whole.
 */
class PageMeta implements Arrayable
{
    public function __construct(
        public readonly string $title,
        public readonly ?string $description = null,
        public readonly bool $noindex = false,
        public readonly ?string $canonical = null,
        public readonly ?string $imageUrl = null,
        public readonly ?string $imageAlt = null,
        public readonly string $type = 'website',
        public readonly ?string $publishedAt = null,
        public readonly ?string $modifiedAt = null,
        public readonly ?string $author = null,
        public readonly ?string $ogTitle = null,
        public readonly ?string $ogDescription = null,
        public readonly bool $nofollow = false,
        public readonly string $twitterCard = 'summary_large_image',
    ) {}

    /**
     * Build from any model using `HasSeo`.
     *
     * @param  object  $model  anything with the HasSeo trait
     */
    public static function for(object $model, ?string $canonical = null): self
    {
        $og = method_exists($model, 'seoOpenGraph') ? $model->seoOpenGraph() : [];

        $image = static::resolveImage($og['image'] ?? null)
            ?? static::defaultShareImage();

        $seo = $model->seo ?? null;

        return new self(
            title: $model->seoTitle(),
            description: $model->seoDescription(),
            noindex: ! $model->seoShouldIndex(),
            // An explicit canonical (this content is a copy of something
            // published elsewhere first) wins; otherwise the page's own URL.
            canonical: $canonical ?? ($seo?->canonical_url ?: self::selfCanonical()),
            imageUrl: $image?->url,
            imageAlt: $image?->alt,
            type: $og['type'] ?? 'website',
            // Not every model has a publish date (a category does not), and
            // strict mode refuses a missing attribute rather than nulling it.
            publishedAt: array_key_exists('published_at', $model->getAttributes()) ? $model->published_at?->toIso8601String() : null,
            modifiedAt: $model->updated_at?->toIso8601String(),
            ogTitle: filled($og['title'] ?? null) && ($og['title'] !== $model->seoTitle()) ? (string) $og['title'] : null,
            ogDescription: filled($og['description'] ?? null) && ($og['description'] !== $model->seoDescription()) ? (string) $og['description'] : null,
            nofollow: (bool) ($seo?->no_follow ?? false),
            twitterCard: (string) ($seo?->twitter_card ?: 'summary_large_image'),
        );
    }

    /**
     * The fallback for a page with no model behind it — search results, the
     * sitemap page.
     *
     * ⚠ The suffix is appended ONCE. Eighteen controllers appended it
     * themselves before passing the title in, and this method appended it
     * again, so every code-backed page shipped with a `<title>` of
     * "Appeals | Greater Hope Foundations | Greater Hope Foundations". The
     * page shell strips every occurrence before printing the h1, which is why
     * nobody saw it on the page — only in the tab, the search result and the
     * share card, which is where a title matters most.
     */
    public static function site(string $title, ?string $description = null, bool $noindex = false): self
    {
        $image = static::defaultShareImage();
        $suffix = (string) setting('seo.title_suffix', '');

        if ($suffix !== '' && str_ends_with($title, $suffix)) {
            $title = substr($title, 0, -strlen($suffix));
        }

        return new self(
            title: $title.$suffix,
            description: $description ?? setting('seo.default_description'),
            noindex: $noindex || ! setting('seo.allow_indexing', false),
            // A paginated list's second page is its own page, not a copy of
            // the first: the canonical keeps `?page=`. Every other parameter
            // (sort, filter, utm) is dropped so those are not indexed twice.
            canonical: self::selfCanonical(),
            imageUrl: $image?->url,
            imageAlt: $image?->alt,
        );
    }

    /**
     * Whether search engines may index this page.
     *
     * ⚠ Two independent reasons not to, and either is enough. The SITE switch
     * is off on staging and no page may override it — a noindexed staging site
     * that leaks one indexable page is worse than useless. The PAGE switch is
     * for a thank-you page or a receipt, reached only by having just done
     * something.
     */
    /**
     * A copy with some slots changed. Controllers that know more than the
     * model — a post's featured image, its author — override those and keep
     * everything else, rather than rebuilding the object and losing the
     * editor's Open Graph and robots choices on the way.
     *
     * @param  array<string, mixed>  $changes
     */
    public function with(array $changes): self
    {
        $current = get_object_vars($this);

        return new self(...array_merge($current, $changes));
    }

    /** The current URL with `page` kept and everything else dropped. */
    public static function selfCanonical(): string
    {
        $page = (int) request()->query('page', 1);

        return $page > 1 ? request()->url().'?page='.$page : request()->url();
    }

    public function shouldIndex(): bool
    {
        return ! $this->noindex && (bool) setting('seo.allow_indexing', false);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'description' => $this->description,
            'canonical' => $this->canonical,
            'image' => $this->imageUrl,
            'type' => $this->type,
        ];
    }

    /**
     * A media id turned into a URL and its alt text.
     *
     * Refuses an unpublishable file for the same reason the image component
     * does: a share image is fetched by Facebook, WhatsApp and every scraper
     * that sees the link, so an unsanitised photograph shared as an OG image is
     * a photograph's GPS coordinates handed to every one of them.
     */
    private static function resolveImage(mixed $mediaId): ?object
    {
        if (blank($mediaId)) {
            return null;
        }

        $media = Media::find($mediaId);

        if ($media === null || ! $media->isPublishable()) {
            return null;
        }

        return (object) [
            'url' => $media->conversionUrl('card'),
            'alt' => $media->altText(),
        ];
    }

    private static function defaultShareImage(): ?object
    {
        return static::resolveImage(setting('seo.og_image'));
    }
}
