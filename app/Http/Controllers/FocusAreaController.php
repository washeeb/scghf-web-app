<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Cause;
use App\Models\FocusArea;
use App\Models\Project;
use App\Support\PageMeta;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * What the foundation works on.
 *
 * ── The index is the answer to "what do you actually do?" ───────────────────
 *
 * Which is the first question anybody asks of a charity they have not heard of,
 * and the one a page of project titles does not answer. Each focus area carries
 * a count of live projects, so the page shows scale as well as intent — "three
 * areas" and "three areas, nineteen projects" are different claims.
 *
 * ── An empty focus area is still listed ─────────────────────────────────────
 *
 * A theme the foundation works in but has no published project for yet is a
 * true statement about the organisation. Hiding it would make the page describe
 * the CMS rather than the foundation.
 */
class FocusAreaController extends Controller
{
    public function index(): View
    {
        return view('focus-areas.index', [
            'focusAreas' => FocusArea::query()
                ->where('is_active', true)
                ->withCount(['projects' => fn (Builder $q) => $this->livePublished($q)])
                ->orderBy('sort_order')
                ->get(),

            'meta' => PageMeta::site(
                __('What we do'),
                __('The areas :name works in.', ['name' => setting('general.short_name', config('app.name'))]),
            ),
            'crumbs' => [
                ['label' => __('Home'), 'url' => url('/')],
                ['label' => __('What we do'), 'url' => null],
            ],
        ]);
    }

    public function show(FocusArea $focusArea): View
    {
        if (! $focusArea->is_active) {
            throw new NotFoundHttpException;
        }

        return view('focus-areas.show', [
            'focusArea' => $focusArea,

            'projects' => $focusArea->projects()
                ->where('is_published', true)
                ->with(['featuredImage', 'locations'])
                ->get()
                ->filter(fn (Project $project): bool => $project->isLive())
                ->values(),

            /*
             * The appeals a visitor can act on, in this area.
             *
             * A page describing work with no way to support it is a page that
             * has told somebody what to care about and then stopped. Only
             * appeals still accepting money are shown — a closed one here is an
             * invitation to give to something that has finished.
             */
            'causes' => Cause::query()
                ->live()
                ->whereHas('project.focusAreas', fn (Builder $q) => $q->whereKey($focusArea->getKey()))
                ->with('featuredImage')
                ->get()
                ->filter(fn (Cause $cause): bool => $cause->acceptsDonations())
                ->values(),

            'meta' => PageMeta::for($focusArea, route('focus-areas.show', $focusArea)),
            'crumbs' => [
                ['label' => __('Home'), 'url' => url('/')],
                ['label' => __('What we do'), 'url' => route('focus-areas.index')],
                ['label' => $focusArea->name, 'url' => null],
            ],
        ]);
    }

    /**
     * Published, and past its publish date.
     *
     * Written once because the count on the index and the list on the detail
     * page must agree — a page saying "4 projects" above a list of three is the
     * kind of small wrongness that makes a visitor doubt the rest of it.
     */
    private function livePublished(Builder $query): Builder
    {
        return $query
            ->where('is_published', true)
            ->where(fn (Builder $q) => $q->whereNull('published_at')->orWhere('published_at', '<=', now()));
    }
}
