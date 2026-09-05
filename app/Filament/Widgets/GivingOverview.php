<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\DonationStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Donation;
use App\Models\Subscription;
use App\ValueObjects\Money;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

/**
 * What came in, and what is coming.
 *
 * ── Only completed donations are counted ────────────────────────────────────
 *
 * A pending donation is somebody who opened the Paystack page. Counting those
 * would give the foundation a number that goes up when nobody pays, which is
 * the one thing a fundraising figure must never do. Payment truth comes from
 * the webhook; `completed` is what the webhook set.
 *
 * ── The comparison is to the same point last month ──────────────────────────
 *
 * Not to the whole of last month. On the 3rd, "down 89% on last month" is true
 * and useless — three days are not thirty. Comparing like with like is the
 * difference between a number somebody acts on and one they learn to ignore.
 */
class GivingOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    public static function canView(): bool
    {
        return auth()->user()?->can('donations.view') ?? false;
    }

    protected function getStats(): array
    {
        $currency = (string) setting('donations.currency_code', 'GHS');

        $thisMonth = $this->raisedBetween(now()->startOfMonth(), now());
        $samePointLastMonth = $this->raisedBetween(
            now()->subMonthNoOverflow()->startOfMonth(),
            now()->subMonthNoOverflow(),
        );

        return [
            Stat::make(__('Raised this month'), Money::ofMinor($thisMonth, $currency)->format())
                ->description($this->comparison($thisMonth, $samePointLastMonth))
                ->descriptionIcon($thisMonth >= $samePointLastMonth
                    ? 'heroicon-m-arrow-trending-up'
                    : 'heroicon-m-arrow-trending-down')
                ->color($thisMonth >= $samePointLastMonth ? 'success' : 'warning')
                ->chart($this->dailyTotals()),

            Stat::make(__('Today'), Money::ofMinor(
                $this->raisedBetween(now()->startOfDay(), now()),
                $currency,
            )->format())
                ->description(trans_choice(
                    '{0}No gifts yet today|{1}One gift|[2,*]:count gifts',
                    $this->countBetween(now()->startOfDay(), now()),
                    ['count' => $this->countBetween(now()->startOfDay(), now())],
                )),

            /*
             * Recurring donors are the number that actually predicts next year.
             * A one-off appeal is an event; a standing order is a budget.
             */
            Stat::make(__('Recurring donors'), (string) Subscription::query()
                ->where('status', SubscriptionStatus::Active->value)
                ->distinct('donor_id')
                ->count('donor_id'))
                ->description($this->failingSubscriptions())
                ->color($this->failingCount() > 0 ? 'warning' : 'gray'),
        ];
    }

    /** Minor units raised between two moments. */
    private function raisedBetween(\DateTimeInterface $from, \DateTimeInterface $to): int
    {
        return (int) Donation::query()
            ->where('status', DonationStatus::Completed->value)
            ->whereBetween('paid_at', [$from, $to])
            ->sum('amount_minor');
    }

    private function countBetween(\DateTimeInterface $from, \DateTimeInterface $to): int
    {
        return Donation::query()
            ->where('status', DonationStatus::Completed->value)
            ->whereBetween('paid_at', [$from, $to])
            ->count();
    }

    private function comparison(int $now, int $before): string
    {
        if ($before === 0) {
            // "Up ∞%" is not a sentence. When there is nothing to compare
            // against, say so rather than invent a percentage.
            return $now === 0
                ? __('Nothing yet this month')
                : __('No gifts by this point last month');
        }

        $change = (int) round((($now - $before) / $before) * 100);

        return $change >= 0
            ? __('Up :percent% on this point last month', ['percent' => $change])
            : __('Down :percent% on this point last month', ['percent' => abs($change)]);
    }

    /**
     * Thirty days of daily totals, for the sparkline.
     *
     * Grouped in SQL rather than fetched and summed in PHP — a foundation with
     * a few thousand donations would otherwise hydrate all of them to draw a
     * line thirty pixels wide.
     *
     * @return array<int, int>
     */
    private function dailyTotals(): array
    {
        $rows = Donation::query()
            ->where('status', DonationStatus::Completed->value)
            ->where('paid_at', '>=', now()->subDays(29)->startOfDay())
            ->select([
                DB::raw('DATE(paid_at) as day'),
                DB::raw('SUM(amount_minor) as total'),
            ])
            ->groupBy('day')
            ->pluck('total', 'day');

        return collect(range(29, 0))
            ->map(fn (int $back): int => (int) ($rows[now()->subDays($back)->toDateString()] ?? 0))
            ->all();
    }

    private function failingCount(): int
    {
        return Subscription::query()->where('status', SubscriptionStatus::Failing->value)->count();
    }

    private function failingSubscriptions(): string
    {
        $failing = $this->failingCount();

        return $failing === 0
            ? __('All collecting normally')
            : trans_choice(
                '{1}One standing order is failing|[2,*]:count standing orders are failing',
                $failing,
                ['count' => $failing],
            );
    }
}
