<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\SeoMeta;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * Per-entity SEO overrides with a fallback chain.
 *
 * The point is that an editor should almost never have to fill these in. A page
 * with no SEO row still emits a complete, sensible title and description by
 * falling back to its own fields and then to the `seo` settings group. The
 * override exists for the handful of pages where the default is not good
 * enough — not as a field everyone must remember.
 *
 * Resolution order, first non-empty wins:
 *   1. the SEO override
 *   2. the entity's own title / excerpt
 *   3. the site defaults in settings
 */
trait HasSeo
{
    /** @return MorphOne<SeoMeta, $this> */
    public function seo(): MorphOne
    {
        return $this->morphOne(SeoMeta::class, 'seoable');
    }

    /**
     * The attribute this model uses as its natural title.
     *
     * Overridden by models whose title lives under a different name.
     */
    protected function seoTitleSource(): ?string
    {
        return $this->title ?? $this->name ?? null;
    }

    protected function seoDescriptionSource(): ?string
    {
        return $this->excerpt ?? $this->description ?? null;
    }

    public function seoTitle(): string
    {
        $title = $this->seo?->title
            ?: $this->seoTitleSource()
            ?: setting('seo.default_title', config('app.name'));

        return $title.setting('seo.title_suffix', '');
    }

    public function seoDescription(): ?string
    {
        $description = $this->seo?->description
            ?: $this->seoDescriptionSource()
            ?: setting('seo.default_description');

        if ($description === null) {
            return null;
        }

        // Search engines truncate around 160 characters. Cutting on a word
        // boundary rather than mid-word is the difference between a snippet
        // that reads and one that looks broken.
        return str($description)->stripTags()->squish()->limit(158)->toString();
    }

    /** Whether search engines should index this. */
    public function seoShouldIndex(): bool
    {
        // Site-wide indexing is off on staging, and no per-page override may
        // turn it on — a noindexed staging site that leaks one indexable page
        // is worse than useless.
        if (! setting('seo.allow_indexing', false)) {
            return false;
        }

        return ! ($this->seo?->no_index ?? false);
    }

    /** @return array<string, mixed> */
    public function seoOpenGraph(): array
    {
        return [
            'title' => $this->seo?->og_title ?: $this->seoTitle(),
            'description' => $this->seo?->og_description ?: $this->seoDescription(),
            'type' => $this->seo?->og_type ?? 'website',
            'image' => $this->seo?->og_image_id,
        ];
    }
}
