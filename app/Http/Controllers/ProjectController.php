<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ProjectStatus;
use App\Models\FocusArea;
use App\Models\Project;
use App\Models\ProjectLocation;
use App\Support\PageMeta;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * What the foundation is doing, and where.
 *
 * ── The filters are query-string links, not a JavaScript widget ─────────────
 *
 * Every combination is a real URL: shareable, bookmarkable, crawlable, and
 * working before a single script has loaded. A filter panel that needs
 * JavaScript is one that shows an unfiltered list to a visitor on a slow
 * connection and never tells them why.
 *
 * ── Filter options come from the data, not a hardcoded list ────────────────
 *
 * The regions offered are the regions projects are actually in. A dropdown of
 * all sixteen Ghanaian regions on a site with work in three is fourteen dead
 * ends, and the visitor who picks one learns nothing except that the filter
 * does not work.
 *
 * ── A project's own page is the transparency page ───────────────────────────
 *
 * Budget, progress, milestones, locations, partners and documents together are
 * what let somebody check the claim rather than take it. Milestones marked
 * `is_public` only: an internal target the team missed is not a promise the
 * foundation made to the public.
 */
class ProjectController extends Controller
{
    private const PER_PAGE = 9;

    public function index(Request $request): View
    {
        $query = $this->live()->with(['featuredImage', 'focusAreas', 'locations']);

        $filters = [
            'focus' => $request->string('focus')->toString(),
            'region' => $request->string('region')->toString(),
            'status' => $request->string('status')->toString(),
            'year' => $request->string('year')->toString(),
        ];

        if ($filters['focus'] !== '') {
            $query->whereHas('focusAreas', fn (Builder $q) => $q->where('slug', $filters['focus']));
        }

        if ($filters['region'] !== '') {
            $query->whereHas('locations', fn (Builder $q) => $q->where('region', $filters['region']));
        }

        if ($filters['status'] !== '' && ProjectStatus::tryFrom($filters['status']) !== null) {
            $query->where('status', $filters['status']);
        }

        if ($filters['year'] !== '' && ctype_digit($filters['year'])) {
            /*
             * A project counts for a year if it was RUNNING in it, not only if
             * it started in it. A three-year programme filtered out of years
             * two and three would make the foundation look like it stopped.
             */
            $year = (int) $filters['year'];

            $query->whereYear('starts_on', '<=', $year)
                ->where(fn (Builder $q) => $q
                    ->whereNull('ends_on')
                    ->orWhereYear('ends_on', '>=', $year));
        }

        return view('projects.index', [
            'projects' => $query
                ->orderByDesc('is_featured')
                ->orderByDesc('starts_on')
                ->paginate(self::PER_PAGE)
                ->withQueryString(),

            'filters' => $filters,
            'focusAreas' => $this->focusAreaOptions(),
            'regions' => $this->regionOptions(),
            'years' => $this->yearOptions(),
            'statuses' => $this->statusOptions(),

            'meta' => PageMeta::site(
                __('Our projects').setting('seo.title_suffix', ''),
                __('What we are doing, where, and how far along it is.'),
            ),
            'crumbs' => [
                ['label' => __('Home'), 'url' => url('/')],
                ['label' => __('Projects'), 'url' => null],
            ],
        ]);
    }

    public function show(Project $project): View
    {
        if (! $project->isLive()) {
            throw new NotFoundHttpException;
        }

        $project->load([
            'featuredImage',
            'focusAreas',
            'locations',
            'partners.logo',
            'documents.media',
            'tags',
        ]);

        return view('projects.show', [
            'project' => $project,

            // Public milestones only. See the note at the top of this class.
            'milestones' => $project->milestones()->where('is_public', true)->get(),

            'updates' => $project->updates()
                ->where('is_published', true)
                ->where(fn (Builder $q) => $q->whereNull('published_at')->orWhere('published_at', '<=', now()))
                ->with('image')
                ->latest('published_at')
                ->limit(5)
                ->get(),

            /*
             * The appeals attached to this project, still open. A project page
             * with no way to support it has told somebody what to care about
             * and then stopped.
             */
            'causes' => $project->causes()
                ->live()
                ->with('featuredImage')
                ->get()
                ->filter(fn ($cause): bool => $cause->acceptsDonations())
                ->values(),

            'meta' => PageMeta::for($project, route('projects.show', $project)),
            'crumbs' => [
                ['label' => __('Home'), 'url' => url('/')],
                ['label' => __('Projects'), 'url' => route('projects.index')],
                ['label' => $project->title, 'url' => null],
            ],
        ]);
    }

    /** @return Builder<Project> */
    private function live(): Builder
    {
        return Project::query()
            ->where('is_published', true)
            ->where(fn (Builder $q) => $q->whereNull('published_at')->orWhere('published_at', '<=', now()))
            ->whereIn('status', collect(ProjectStatus::cases())
                ->filter(fn (ProjectStatus $status): bool => $status->isPubliclyListable())
                ->map(fn (ProjectStatus $status): string => $status->value)
                ->all());
    }

    /** @return Collection<int, FocusArea> */
    private function focusAreaOptions(): Collection
    {
        return FocusArea::query()
            ->where('is_active', true)
            ->whereHas('projects', fn (Builder $q) => $q->where('is_published', true))
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * The regions work is actually happening in.
     *
     * @return Collection<int, string>
     */
    private function regionOptions(): Collection
    {
        return ProjectLocation::query()
            ->whereNotNull('region')
            ->whereHas('project', fn (Builder $q) => $q->where('is_published', true))
            ->distinct()
            ->orderBy('region')
            ->pluck('region');
    }

    /**
     * The years work was running.
     *
     * From the earliest project start to the current year, so a visitor is
     * never offered a year with nothing in it — and never denied one in the
     * middle of a long programme.
     *
     * @return Collection<int, int>
     */
    private function yearOptions(): Collection
    {
        $earliest = $this->live()->whereNotNull('starts_on')->min('starts_on');

        if ($earliest === null) {
            return collect();
        }

        $from = (int) date('Y', strtotime((string) $earliest));

        return collect(range((int) now()->format('Y'), $from));
    }

    /** @return array<string, string> */
    private function statusOptions(): array
    {
        return collect(ProjectStatus::cases())
            ->filter(fn (ProjectStatus $status): bool => $status->isPubliclyListable())
            ->mapWithKeys(fn (ProjectStatus $status): array => [$status->value => $status->label()])
            ->all();
    }
}
