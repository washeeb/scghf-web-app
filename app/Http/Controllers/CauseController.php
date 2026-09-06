<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Cause;
use App\Models\Donation;
use App\Support\PageMeta;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The appeals people can give to.
 *
 * ── The progress bar reads the cached total, and that is deliberate ─────────
 *
 * `causes.raised_minor` is incremented atomically as each completed donation
 * lands. Summing the donations table on every page view would be a full scan on
 * the hottest page the site has, on a host with one small database — and the
 * cached column is corrected by `recalculateRaised()` whenever an offline gift
 * is amended or a donation refunded.
 *
 * ── The donor wall respects anonymity at the query, not in the view ─────────
 *
 * `Donation::publicDonorName()` already returns "Anonymous" for a gift marked so,
 * but the AMOUNT is a different question. A wall showing "Anonymous — GH₵
 * 5,000" beside a list of named gifts identifies the anonymous donor to anybody
 * who knows what they gave. Amounts are omitted entirely.
 *
 * ── A closed appeal is still readable ───────────────────────────────────────
 *
 * `isLive()` decides whether the page exists; `acceptsDonations()` decides
 * whether it takes money. An appeal that reached its goal or passed its closing
 * date keeps its page — that page is the record of what was raised and what it
 * did, and deleting it turns every link anybody shared into a 404.
 */
class CauseController extends Controller
{
    private const PER_PAGE = 9;

    /** Enough to show momentum without turning the page into a ledger. */
    private const RECENT_DONORS = 8;

    public function index(): View
    {
        $causes = Cause::query()
            ->live()
            ->with(['featuredImage', 'project'])
            ->orderByDesc('is_featured')
            ->orderBy('sort_order')
            ->paginate(self::PER_PAGE);

        return view('causes.index', [
            /*
             * Open appeals first, closed ones after. Both are listed: a closed
             * appeal is evidence the foundation finishes what it starts, which
             * is worth more to a hesitant donor than a page of open asks.
             */
            'causes' => $causes,

            'meta' => PageMeta::site(
                __('Appeals').setting('seo.title_suffix', ''),
                __('What your giving pays for.'),
            ),
            'crumbs' => [
                ['label' => __('Home'), 'url' => url('/')],
                ['label' => __('Appeals'), 'url' => null],
            ],
        ]);
    }

    public function show(Cause $cause): View
    {
        if (! $cause->isLive()) {
            throw new NotFoundHttpException;
        }

        $cause->load(['featuredImage', 'project']);

        return view('causes.show', [
            'cause' => $cause,
            'donors' => $this->recentDonors($cause),

            'updates' => $cause->updates()
                ->where('is_published', true)
                ->where(fn (Builder $q) => $q->whereNull('published_at')->orWhere('published_at', '<=', now()))
                ->with('image')
                ->latest('published_at')
                ->limit(5)
                ->get(),

            'meta' => PageMeta::for($cause, route('causes.show', $cause)),
            'crumbs' => [
                ['label' => __('Home'), 'url' => url('/')],
                ['label' => __('Appeals'), 'url' => route('causes.index')],
                ['label' => $cause->title, 'url' => null],
            ],
        ]);
    }

    /**
     * The most recent supporters, by name only.
     *
     * ⚠ No amounts, and no names for anonymous gifts. `site.show_donor_wall` is
     * the foundation's switch over the whole feature — seeded on, and a donor
     * who assumed their gift was private is not somebody to surprise.
     *
     * @return Collection<int, string>
     */
    private function recentDonors(Cause $cause): Collection
    {
        if (! setting('site.show_donor_wall', true)) {
            return collect();
        }

        return Donation::query()
            ->where('cause_id', $cause->getKey())
            ->completed()
            ->latest('paid_at')
            ->limit(self::RECENT_DONORS)
            ->get()
            ->map(fn (Donation $donation): string => $donation->publicDonorName());
    }
}
