<?php

declare(strict_types=1);

namespace App\Donors;

use App\Models\CauseUpdate;
use App\Models\Donation;
use App\Models\Donor;
use App\Models\ImpactMetric;
use App\ValueObjects\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * "Your gifts, and what they did" — the story a donor sees in their account.
 *
 * ── What goes on the timeline ───────────────────────────────────────────────
 *
 * Three kinds of entry, newest first:
 *
 *   gift     — each completed donation: the date, the amount, the appeal.
 *   update   — a published update on an appeal the donor gave to, dated
 *              AFTER their first gift to it. An update from before they
 *              gave is the appeal's history, not something their money did.
 *   figures  — for each project behind those appeals, the public impact
 *              metrics since the donor's first gift to any of its appeals
 *              ("since your first gift: 140 children fed"), as one entry
 *              per project, dated today so it heads the list — it is the
 *              running total, not an event.
 *
 * ── What it is careful about ────────────────────────────────────────────────
 *
 * The figures go through `ImpactMetric::publishedTotal()`, which applies
 * the disclosure control: a count of people below the minimum group size is
 * suppressed, exactly as on the public impact page. A signed-in donor sees
 * nothing about beneficiaries that the public cannot. And the wording says
 * "since your first gift", never "because of your gift": one donor's GH₵ 50
 * did not feed 140 children, and the foundation's supporters can tell the
 * difference between a story and a claim.
 *
 * Bounded reads: the donations are the donor's own (a handful to a few
 * hundred), the updates and metrics are per appeal. Nothing here is paged
 * because nothing here is unbounded.
 */
final class ImpactTimeline
{
    /** @var array<string, string> */
    public const KINDS = ['gift' => 'gift', 'update' => 'update', 'figures' => 'figures'];

    /**
     * @return Collection<int, array{kind: string, at: Carbon, title: string, body: string|null, url: string|null, amount: Money|null, figures: array<int, array{label: string, value: string}>}>
     */
    public function for(Donor $donor): Collection
    {
        $gifts = Donation::query()
            ->where('donor_id', $donor->getKey())
            ->completed()
            ->with(['cause:id,title,slug,project_id', 'cause.project:id,title,slug'])
            ->orderBy('paid_at')
            ->get();

        if ($gifts->isEmpty()) {
            return collect();
        }

        /** @var Collection<int, Carbon> $firstGiftByCause cause id → the first paid_at */
        $firstGiftByCause = $gifts
            ->filter(fn (Donation $d): bool => $d->cause_id !== null && $d->paid_at !== null)
            ->groupBy('cause_id')
            ->map(fn (Collection $group): Carbon => $group->min('paid_at'));

        $entries = collect();

        foreach ($gifts as $gift) {
            $entries->push([
                'kind' => 'gift',
                'at' => $gift->paid_at ?? $gift->created_at,
                'title' => __('You gave :amount', ['amount' => $gift->amount->format()]),
                'body' => $gift->cause ? $gift->cause->title : __('General Fund'),
                'url' => $gift->cause ? route('causes.show', $gift->cause) : null,
                'amount' => $gift->amount,
                'figures' => [],
            ]);
        }

        foreach ($firstGiftByCause as $causeId => $since) {
            $updates = CauseUpdate::query()
                ->where('cause_id', $causeId)
                ->live()
                ->where('published_at', '>=', $since)
                ->with('cause:id,title,slug')
                ->get();

            foreach ($updates as $update) {
                $entries->push([
                    'kind' => 'update',
                    'at' => $update->published_at ?? $update->created_at,
                    'title' => $update->title,
                    'body' => $update->cause?->title,
                    'url' => $update->cause ? route('causes.show', $update->cause).'#updates-heading' : null,
                    'amount' => null,
                    'figures' => [],
                ]);
            }
        }

        // The figures: one entry per project, since the earliest gift to
        // any of its appeals.
        $firstGiftByProject = $gifts
            ->filter(fn (Donation $d): bool => $d->cause?->project_id !== null && $d->paid_at !== null)
            ->groupBy(fn (Donation $d): int => (int) $d->cause->project_id)
            ->map(fn (Collection $group): Carbon => $group->min('paid_at'));

        foreach ($firstGiftByProject as $projectId => $since) {
            $metrics = ImpactMetric::query()
                ->where('project_id', $projectId)
                ->published()
                ->with('project:id,title,slug')
                ->get();

            $figures = [];

            foreach ($metrics as $metric) {
                $total = $metric->publishedTotal($since);

                if ($total === null || $total === 0.0) {
                    continue;
                }

                $figures[] = ['label' => $metric->name, 'value' => $metric->format($total)];
            }

            if ($figures === []) {
                continue;
            }

            $project = $metrics->first()?->project;

            $entries->push([
                'kind' => 'figures',
                'at' => now(),
                'title' => __(':project — since your first gift', ['project' => $project ? $project->title : __('This work')]),
                'body' => __('Counting from :date.', ['date' => $since->format('j F Y')]),
                'url' => $project ? route('projects.show', $project) : null,
                'amount' => null,
                'figures' => $figures,
            ]);
        }

        return $entries->sortByDesc('at')->values();
    }
}
