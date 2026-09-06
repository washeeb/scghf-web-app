<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Cause;
use App\Models\Donation;
use App\Models\Payout;
use App\Support\DisclosureControl;
use App\Support\PageMeta;
use App\ValueObjects\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
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

            'spending' => $this->spending($cause),

            'meta' => PageMeta::for($cause, route('causes.show', $cause)),
            'crumbs' => [
                ['label' => __('Home'), 'url' => url('/')],
                ['label' => __('Appeals'), 'url' => route('causes.index')],
                ['label' => $cause->title, 'url' => null],
            ],
        ]);
    }

    /**
     * Where this appeal's money has gone, by category.
     *
     * ⚠ AGGREGATED, never itemised, and that is not a presentation choice. A
     * payout row carries a payee name and frequently a `beneficiary_id` — the
     * person it was spent on. Publishing the rows would publish who received
     * school fees or a medical payment, which is the single most damaging thing
     * this application could disclose.
     *
     * So the public log is category totals. And a category with too FEW
     * payments in it is folded away as well: "Medical — GH₵ 4,500, 1 payment"
     * beside a known beneficiary is an identification, and the minimum group
     * size exists for exactly that shape of leak.
     *
     * Only what has actually been paid. An approved payout that has not left
     * the account is a commitment, and publishing it as spending overstates
     * what the foundation has done.
     *
     * @return Collection<int, array{label: string, amount: Money}>
     */
    private function spending(Cause $cause): Collection
    {
        $minimum = app(DisclosureControl::class)->minimumGroupSize();

        $rows = Payout::query()
            ->where('cause_id', $cause->getKey())
            ->whereNotNull('paid_at')
            ->selectRaw('category, SUM(amount_minor) as total, COUNT(*) as payments')
            ->groupBy('category')
            ->get();

        [$publishable, $folded] = $rows->partition(
            fn ($row): bool => (int) $row->payments >= $minimum
        );

        $result = $publishable
            ->sortByDesc(fn ($row): int => (int) $row->total)
            ->map(fn ($row): array => [
                'label' => Str::headline((string) $row->category),
                'amount' => Money::ofMinor((int) $row->total, $cause->currency),
            ])
            ->values();

        /*
         * Everything too small to publish separately, added together. Shown
         * rather than dropped: a total that does not add up to the disbursed
         * figure invites the question this page exists to answer.
         */
        if ($folded->isNotEmpty()) {
            $result->push([
                'label' => __('Other'),
                'amount' => Money::ofMinor((int) $folded->sum('total'), $cause->currency),
            ]);
        }

        return $result;
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
