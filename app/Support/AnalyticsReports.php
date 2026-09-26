<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\DonationStatus;
use App\Enums\OrderStatus;
use App\Models\ContactMessage;
use App\Models\Donation;
use App\Models\Order;
use App\Models\Subscriber;
use App\Models\Subscription;
use App\Models\VisitorStat;
use App\Models\VolunteerApplication;
use App\ValueObjects\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The director's dashboard, from the database alone.
 *
 * Visits come from `visitor_stats` (Phase 5: a count per day per path,
 * referrer host and device, and nothing about any person). Conversions
 * come from the tables that are the conversions — a completed donation
 * IS the event, not a beacon that might have been blocked. Attribution
 * joins the two through the `utm` column donations and orders carry.
 * No third party is asked anything.
 */
final class AnalyticsReports
{
    private function __construct(private readonly Carbon $from, private readonly Carbon $until) {}

    public static function between(Carbon $from, Carbon $until): self
    {
        return new self($from->copy()->startOfDay(), $until->copy()->endOfDay());
    }

    /** @return array{views: int, sessions: int, days: int, per_day: float} */
    public function traffic(): array
    {
        $views = VisitorStat::viewsBetween($this->from, $this->until);
        $sessions = VisitorStat::sessionsBetween($this->from, $this->until);
        $days = max(1, (int) $this->from->diffInDays($this->until) + 1);

        return ['views' => $views, 'sessions' => $sessions, 'days' => $days, 'per_day' => round($views / $days, 1)];
    }

    /** @return Collection<int, array{day: string, views: int, sessions: int}> */
    public function byDay(): Collection
    {
        return VisitorStat::query()
            ->where('dimension', VisitorStat::DIMENSION_TOTAL)
            ->whereBetween('date', [$this->from->toDateString(), $this->until->toDateString()])
            ->orderBy('date')
            ->get()
            ->map(fn (VisitorStat $row): array => ['day' => $row->date->format('D j M'), 'views' => (int) $row->views, 'sessions' => (int) $row->sessions]);
    }

    /** @return Collection<int, object{value: string, views: int}> */
    public function topPages(int $limit = 15): Collection
    {
        return VisitorStat::top(VisitorStat::DIMENSION_PATH, $this->from, $this->until, $limit);
    }

    /** @return Collection<int, object{value: string, views: int}> */
    public function referrers(int $limit = 10): Collection
    {
        return VisitorStat::top(VisitorStat::DIMENSION_REFERRER, $this->from, $this->until, $limit);
    }

    /** @return Collection<int, object{value: string, views: int}> */
    public function devices(): Collection
    {
        return VisitorStat::top(VisitorStat::DIMENSION_DEVICE, $this->from, $this->until, 5);
    }

    /**
     * The conversions, as counts from the tables that are them.
     *
     * @return array<string, array{label: string, count: int, value: ?Money}>
     */
    public function conversions(): array
    {
        $donations = Donation::query()
            ->where('status', DonationStatus::Completed->value)
            ->whereBetween('created_at', [$this->from, $this->until]);

        $orders = Order::query()
            ->where('status', OrderStatus::Paid->value)
            ->whereBetween('created_at', [$this->from, $this->until]);

        return [
            'donations' => [
                'label' => __('Donations completed'),
                'count' => (clone $donations)->count(),
                'value' => Money::ofMinor((int) (clone $donations)->sum('amount_minor')),
            ],
            'recurring' => [
                'label' => __('Regular gifts started'),
                'count' => Subscription::query()->whereBetween('created_at', [$this->from, $this->until])->count(),
                'value' => null,
            ],
            'orders' => [
                'label' => __('Shop orders paid'),
                'count' => (clone $orders)->count(),
                'value' => Money::ofMinor((int) (clone $orders)->sum('total_minor')),
            ],
            'newsletter' => [
                'label' => __('Newsletter sign-ups confirmed'),
                'count' => Subscriber::query()->whereNotNull('confirmed_at')->whereBetween('confirmed_at', [$this->from, $this->until])->count(),
                'value' => null,
            ],
            'volunteers' => [
                'label' => __('Volunteer applications'),
                'count' => VolunteerApplication::query()->whereNotNull('submitted_at')->whereBetween('submitted_at', [$this->from, $this->until])->count(),
                'value' => null,
            ],
            'enquiries' => [
                'label' => __('Contact messages'),
                'count' => ContactMessage::query()->whereBetween('created_at', [$this->from, $this->until])->count(),
                'value' => null,
            ],
        ];
    }

    /**
     * Income by campaign: what `utm_source` / `utm_campaign` (or the plain
     * `source`) the donation or order carried. "Direct or unknown" is the
     * honest name for a gift with no link behind it.
     *
     * @return Collection<int, array{source: string, campaign: string, donations: int, donated: Money, orders: int, ordered: Money}>
     */
    public function attribution(): Collection
    {
        $rows = collect();

        $keyOf = fn (?string $source, ?array $utm): string => json_encode([
            'source' => $utm['source'] ?? $source ?? '',
            'campaign' => $utm['campaign'] ?? '',
        ]);

        Donation::query()
            ->where('status', DonationStatus::Completed->value)
            ->whereBetween('created_at', [$this->from, $this->until])
            ->get(['source', 'utm', 'amount_minor'])
            ->each(function (Donation $d) use (&$rows, $keyOf): void {
                $k = $keyOf($d->source, $d->utm);
                $row = $rows->get($k, ['donations' => 0, 'donated' => 0, 'orders' => 0, 'ordered' => 0]);
                $row['donations']++;
                $row['donated'] += (int) $d->getRawOriginal('amount_minor');
                $rows->put($k, $row);
            });

        Order::query()
            ->where('status', OrderStatus::Paid->value)
            ->whereBetween('created_at', [$this->from, $this->until])
            ->get(['source', 'utm', 'total_minor'])
            ->each(function (Order $o) use (&$rows, $keyOf): void {
                $k = $keyOf($o->source, $o->utm);
                $row = $rows->get($k, ['donations' => 0, 'donated' => 0, 'orders' => 0, 'ordered' => 0]);
                $row['orders']++;
                $row['ordered'] += (int) $o->getRawOriginal('total_minor');
                $rows->put($k, $row);
            });

        return $rows
            ->map(function (array $row, string $key): array {
                $parts = json_decode($key, true);

                return [
                    'source' => $parts['source'] !== '' ? $parts['source'] : __('Direct or unknown'),
                    'campaign' => $parts['campaign'] !== '' ? $parts['campaign'] : '—',
                    'donations' => $row['donations'],
                    'donated' => Money::ofMinor($row['donated']),
                    'orders' => $row['orders'],
                    'ordered' => Money::ofMinor($row['ordered']),
                ];
            })
            ->sortByDesc(fn (array $r): int => $r['donated']->toMinor() + $r['ordered']->toMinor())
            ->values();
    }
}
