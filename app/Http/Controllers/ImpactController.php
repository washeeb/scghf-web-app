<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\DonationStatus;
use App\Models\Donation;
use App\Models\ImpactMetric;
use App\Models\Payout;
use App\Models\Project;
use App\Models\ProjectLocation;
use App\Models\Volunteer;
use App\Models\VolunteerHour;
use App\Support\PageMeta;
use App\ValueObjects\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * What the foundation has actually done.
 *
 * ── Raised and disbursed, side by side ──────────────────────────────────────
 *
 * Publishing "raised" alone is the number every charity publishes, and it
 * answers nothing a sceptical donor is asking. Publishing what went OUT beside
 * it — from `payouts`, the record of money actually leaving — is the claim that
 * can be checked, and it is the reason this page exists.
 *
 * The two will not match, and should not: money raised in December is spent in
 * March, and a reserve is prudence rather than hoarding. A page that forced
 * them to agree would be lying in a more sophisticated way.
 *
 * ── Every metric goes through `publishedTotal()` ────────────────────────────
 *
 * ⚠ Never `total()`. `publishedTotal()` is the method carrying the disclosure
 * control, which withholds a figure computed from a group too small to publish.
 * This foundation's categories include health, orphan status and widowhood, and
 * "3 widows supported in Bongo" identifies them to anybody in Bongo.
 *
 * ── Nothing here is a live query per widget ─────────────────────────────────
 *
 * The whole page is cached for an hour. It is a public page with a handful of
 * aggregate queries behind it, on a host with one small database, and a
 * transparency page that makes the site slow is one the foundation quietly
 * removes.
 */
class ImpactController extends Controller
{
    private const CACHE_SECONDS = 3600;

    public function __invoke(): View
    {
        $figures = cache()->remember('impact.figures', self::CACHE_SECONDS, fn (): array => [
            'raised' => $this->raised()->toMinor(),
            'disbursed' => $this->disbursed()->toMinor(),
            'donors' => $this->donorCount(),
            'projects' => $this->projectCount(),
            'regions' => $this->byRegion()->all(),
            'volunteers' => $this->volunteerCount(),
            'volunteer_hours' => $this->volunteerHours(),
        ]);

        return view('impact', [
            'raised' => Money::ofMinor($figures['raised']),
            'disbursed' => Money::ofMinor($figures['disbursed']),
            'donors' => $figures['donors'],
            'projects' => $figures['projects'],
            'regions' => collect($figures['regions']),
            'volunteers' => $figures['volunteers'] ?? 0,
            'volunteerHours' => $figures['volunteer_hours'] ?? 0,
            'metrics' => $this->metrics(),

            'meta' => PageMeta::site(
                __('Our impact'),
                __('What has been given, what has been spent, and what it changed.'),
            ),
            'crumbs' => [
                ['label' => __('Home'), 'url' => url('/')],
                ['label' => __('Our impact'), 'url' => null],
            ],
        ]);
    }

    /** Volunteers on the books today. Not people who have applied. */
    private function volunteerCount(): int
    {
        return Volunteer::query()->where('status', Volunteer::STATUS_ACTIVE)->count();
    }

    /**
     * Hours given, ever, counting only entries a second person verified — the
     * same rule the admin total obeys, so the public figure is never larger
     * than the one a funder is shown.
     */
    private function volunteerHours(): int
    {
        return intdiv((int) VolunteerHour::query()->whereNotNull('verified_at')->sum('minutes'), 60);
    }

    /**
     * Completed gifts only.
     *
     * A pending donation is somebody who opened a payment page. Counting those
     * would give the foundation a public figure that rises when nobody pays,
     * which is the one thing a fundraising total must never do.
     */
    private function raised(): Money
    {
        return Money::ofMinor((int) Donation::query()
            ->where('status', DonationStatus::Completed->value)
            ->sum('amount_minor'));
    }

    /**
     * Money that has actually left.
     *
     * From `payouts`, and only those marked paid — an approved payout that has
     * not been sent is a commitment, not a disbursement, and publishing it as
     * one would overstate what the foundation has done.
     */
    private function disbursed(): Money
    {
        return Money::ofMinor((int) Payout::query()
            ->whereNotNull('paid_at')
            ->sum('amount_minor'));
    }

    /**
     * How many people have given.
     *
     * Distinct donors rather than gifts. "1,400 donations" from 40 people is a
     * different foundation from 1,400 supporters, and the second is the honest
     * reading of "how many people back this".
     */
    private function donorCount(): int
    {
        return (int) Donation::query()
            ->where('status', DonationStatus::Completed->value)
            ->whereNotNull('donor_id')
            ->distinct()
            ->count('donor_id');
    }

    private function projectCount(): int
    {
        return Project::query()
            ->where('is_published', true)
            ->where(fn (Builder $q) => $q->whereNull('published_at')->orWhere('published_at', '<=', now()))
            ->count();
    }

    /**
     * Published projects per region.
     *
     * Counted from locations, and distinct on the project — a project working
     * in four communities of one region is one project there, not four.
     *
     * @return Collection<int, object{region: string, projects: int}>
     */
    private function byRegion(): Collection
    {
        return ProjectLocation::query()
            ->whereNotNull('region')
            ->whereHas('project', fn (Builder $q) => $q->where('is_published', true))
            ->selectRaw('region, COUNT(DISTINCT project_id) as projects')
            ->groupBy('region')
            ->orderByDesc('projects')
            ->get()
            ->map(fn ($row): array => ['region' => (string) $row->region, 'projects' => (int) $row->projects]);
    }

    /**
     * The metrics the foundation has chosen to publish.
     *
     * ⚠ `publishedTotal()`, never `total()`. See the note at the top of this
     * class: a figure computed from a group too small to publish is withheld,
     * because "3 widows supported in Bongo" identifies them.
     *
     * A metric whose value is withheld is dropped from the page entirely rather
     * than shown as "—". A dash invites somebody to ask what it was.
     *
     * @return Collection<int, array{name: string, value: string, description: ?string}>
     */
    private function metrics(): Collection
    {
        return ImpactMetric::query()
            ->where('is_public', true)
            ->orderBy('sort_order')
            ->get()
            ->map(function (ImpactMetric $metric): ?array {
                $total = $metric->publishedTotal();

                return $total === null ? null : [
                    'name' => $metric->name,
                    'value' => $metric->format($total),
                    'description' => $metric->description,
                ];
            })
            ->filter()
            ->values();
    }
}
