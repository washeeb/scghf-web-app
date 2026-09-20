<?php

declare(strict_types=1);

namespace App\Blocks;

use App\Models\Cause;
use App\Models\Division;
use App\Models\Faq;
use App\Models\Gallery;
use App\Models\ImpactMetric;
use App\Models\Media;
use App\Models\PageSection;
use App\Models\Partner;
use App\Models\Project;
use App\Models\TeamMember;
use App\Models\Testimonial;
use Illuminate\Support\Collection;
use Throwable;

/**
 * The records a block needs, fetched once, outside the view.
 *
 * ── Why the queries are not in the Blade files ──────────────────────────────
 *
 * A `@foreach (Cause::live()->get() as $cause)` in a template works and is
 * untestable, invisible to anybody auditing what a page costs, and the usual
 * route to an N+1 that only appears once the site has real content on it. Here
 * every query is in one file, each has a limit, and each eager-loads what its
 * view will ask for.
 *
 * ── Nothing here trusts the block's own data for a limit ────────────────────
 *
 * `limit` comes from an editor, so it is clamped. A block asking for ten
 * thousand causes is a page that times out, and the editor who typed it would
 * have no idea why.
 *
 * ── Consent is a query condition, not a checkbox somebody remembers ─────────
 *
 * Testimonials and galleries carry `has_consent`, and their models already
 * refuse to be published without it. The scopes below rely on that rather than
 * re-checking, which is the right dependency: the guarantee belongs to the
 * model, and a second check here would be a second place to get it wrong.
 */
class BlockDataResolver
{
    /** Nobody needs more than this on one page, whatever they typed. */
    private const MAX_ITEMS = 24;

    /**
     * Everything the section's view needs beyond the section itself.
     *
     * Returns an empty array for a block that needs no query — most of them.
     * Failures return empty rather than throwing: a block whose data cannot be
     * loaded should be an absent block, not a 500 on a donation page.
     *
     * @return array<string, mixed>
     */
    public function for(PageSection $section): array
    {
        try {
            return match ($section->block_type) {
                'featured-causes' => ['causes' => $this->causes($section)],
                'featured-projects' => ['projects' => $this->projects($section)],
                'divisions' => ['divisions' => $this->divisions()],
                'testimonials' => ['testimonials' => $this->testimonials($section)],
                'partners' => ['partners' => $this->partners()],
                'team' => ['members' => $this->team($section)],
                'faq' => ['faqs' => $this->faqs($section)],
                'impact-stats' => ['metrics' => $this->metrics($section)],
                'gallery' => ['gallery' => $this->gallery($section)],
                'hero', 'page-header', 'split-content', 'cta-band', 'video' => $this->media($section),
                default => [],
            };
        } catch (Throwable) {
            return [];
        }
    }

    /** @return Collection<int, Cause> */
    private function causes(PageSection $section): Collection
    {
        return Cause::query()
            ->live()
            ->when($section->field('division_id'), fn ($q, $id) => $q->where('division_id', $id))
            ->with('featuredImage')
            ->orderByDesc('is_featured')
            ->orderBy('sort_order')
            ->limit($this->limit($section, 3))
            ->get();
    }

    /** @return Collection<int, Project> */
    private function projects(PageSection $section): Collection
    {
        return Project::query()
            ->live()
            ->when($section->field('division_id'), fn ($q, $id) => $q->where('division_id', $id))
            ->with('featuredImage')
            ->latest('id')
            ->limit($this->limit($section, 3))
            ->get();
    }

    /** @return Collection<int, Division> */
    private function divisions(): Collection
    {
        return Division::query()->active()->with('heroImage')->orderBy('sort_order')->get();
    }

    /** @return Collection<int, Testimonial> */
    private function testimonials(PageSection $section): Collection
    {
        /*
         * `published` is enough. `Testimonial` refuses to save as published
         * without recorded consent — the guarantee belongs to the model, and
         * re-checking it here would be a second place for it to be wrong.
         */
        return Testimonial::query()
            ->published()
            ->with('photo')
            ->orderByDesc('is_featured')
            ->orderBy('sort_order')
            ->limit($this->limit($section, 3))
            ->get();
    }

    /** @return Collection<int, Partner> */
    private function partners(): Collection
    {
        return Partner::query()
            ->published()
            ->current()
            ->with('logo')
            ->orderBy('sort_order')
            ->limit(self::MAX_ITEMS)
            ->get();
    }

    /** @return Collection<int, TeamMember> */
    private function team(PageSection $section): Collection
    {
        return TeamMember::query()
            ->published()
            ->current()
            ->when($section->field('department_id'), fn ($q, $id) => $q->where('team_department_id', $id))
            ->with('photo')
            ->orderBy('sort_order')
            ->limit(self::MAX_ITEMS)
            ->get();
    }

    /** @return Collection<int, Faq> */
    private function faqs(PageSection $section): Collection
    {
        return Faq::query()
            ->published()
            ->when($section->field('category_id'), fn ($q, $id) => $q->where('faq_category_id', $id))
            ->orderBy('sort_order')
            ->limit(self::MAX_ITEMS)
            ->get();
    }

    /**
     * @return Collection<int, ImpactMetric>
     */
    private function metrics(PageSection $section): Collection
    {
        $ids = array_filter((array) $section->field('metric_ids', []));

        return ImpactMetric::query()
            ->published()
            /*
             * An explicit list when the editor chose one, otherwise the
             * featured metrics. Never "all of them" — an impact band with
             * fourteen numbers in it communicates nothing.
             */
            ->when($ids !== [], fn ($q) => $q->whereIn('id', $ids), fn ($q) => $q->where('is_featured', true))
            /*
             * The date each figure is "as at", as one aggregate rather than a
             * query per metric. The view shows it because an unsourced
             * statistic on a fundraising site is a trust risk.
             */
            ->withMax('values', 'period_end')
            ->orderBy('sort_order')
            ->limit(8)
            ->get();
    }

    private function gallery(PageSection $section): ?Gallery
    {
        $id = $section->field('gallery_id');

        if ($id === null) {
            return null;
        }

        return Gallery::query()
            ->published()
            ->with(['items' => fn ($q) => $q->orderBy('sort_order')->limit(self::MAX_ITEMS), 'items.media'])
            ->find($id);
    }

    /**
     * The images a presentational block refers to by id.
     *
     * Loaded here rather than in the view so a block with three image fields is
     * three queries at render time rather than three per section per request —
     * and so `Media` is fetched as a model that knows whether it is publishable
     * rather than as a bare id the view has to interpret.
     *
     * @return array<string, mixed>
     */
    private function media(PageSection $section): array
    {
        $ids = array_filter([
            'image' => $section->field('image'),
            'image_mobile' => $section->field('image_mobile'),
            'poster' => $section->field('poster'),
        ]);

        if ($ids === []) {
            return [];
        }

        $media = Media::query()->whereIn('id', $ids)->get()->keyBy('id');

        return collect($ids)
            ->map(fn (mixed $id) => $media->get($id))
            ->filter()
            ->all();
    }

    /** An editor's number, clamped to something a page can render. */
    private function limit(PageSection $section, int $default): int
    {
        $requested = (int) $section->field('limit', $default);

        return max(1, min($requested ?: $default, self::MAX_ITEMS));
    }
}
