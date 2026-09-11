<?php

declare(strict_types=1);

namespace App\Payments;

use App\Enums\DonationStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Donation;
use App\Models\Donor;
use App\Models\Subscription;
use App\ValueObjects\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The finance reports, as numbers.
 *
 * ── Completed gifts only, by the date the money arrived ─────────────────────
 *
 * Every figure here counts `completed` donations by `paid_at`. A pending gift
 * is not income; a refunded one is not either; and a gift that was started on
 * the 31st and confirmed on the 1st belongs to the month the money came.
 *
 * ── Integer arithmetic in the database, Money at the edge ───────────────────
 *
 * Sums are `SUM(amount_minor)` and come back as integers. They become Money
 * only when they are about to be displayed, so no float ever touches a
 * total.
 *
 * ── A service, not a Livewire page ──────────────────────────────────────────
 *
 * So the numbers are testable with a database and nothing else, and so the
 * same figures can feed a dashboard widget, an export, or an email to the
 * trustees without being recomputed three ways.
 */
final class GivingReports
{
    public function __construct(private readonly Carbon $from, private readonly Carbon $until) {}

    public static function between(Carbon $from, Carbon $until): self
    {
        return new self($from->copy()->startOfDay(), $until->copy()->endOfDay());
    }

    /** @return array{raised: Money, net: Money, fees: Money, gifts: int, donors: int, average: Money, refunded: Money} */
    public function summary(): array
    {
        $row = $this->completed()
            ->selectRaw('COALESCE(SUM(amount_minor), 0) as raised, COALESCE(SUM(net_minor), 0) as net, COALESCE(SUM(fee_minor), 0) as fees, COUNT(*) as gifts, COUNT(DISTINCT donor_id) as donors')
            ->first();

        $gifts = (int) ($row->gifts ?? 0);
        $raised = (int) ($row->raised ?? 0);

        $refunded = (int) Donation::query()
            ->where('status', DonationStatus::Refunded->value)
            ->whereBetween('paid_at', [$this->from, $this->until])
            ->sum('amount_minor');

        return [
            'raised' => Money::ofMinor($raised),
            'net' => Money::ofMinor((int) ($row->net ?? 0)),
            'fees' => Money::ofMinor((int) ($row->fees ?? 0)),
            'gifts' => $gifts,
            'donors' => (int) ($row->donors ?? 0),
            'average' => Money::ofMinor($gifts > 0 ? intdiv($raised, $gifts) : 0),
            'refunded' => Money::ofMinor($refunded),
        ];
    }

    /**
     * Totals per period.
     *
     * @param  'day'|'week'|'month'  $granularity
     * @return Collection<int, array{period: string, raised: Money, gifts: int}>
     */
    public function byPeriod(string $granularity = 'day'): Collection
    {
        $format = match ($granularity) {
            'month' => '%Y-%m',
            'week' => '%x-W%v',
            default => '%Y-%m-%d',
        };

        return $this->completed()
            ->selectRaw("DATE_FORMAT(paid_at, '{$format}') as period, SUM(amount_minor) as raised, COUNT(*) as gifts")
            ->groupBy('period')
            ->orderBy('period')
            ->get()
            ->map(fn ($r): array => ['period' => (string) $r->period, 'raised' => Money::ofMinor((int) $r->raised), 'gifts' => (int) $r->gifts]);
    }

    /** @return Collection<int, array{label: string, raised: Money, gifts: int}> */
    public function byCause(): Collection
    {
        return $this->grouped('causes.title', fn (Builder $q) => $q->leftJoin('causes', 'causes.id', '=', 'donations.cause_id'));
    }

    /** @return Collection<int, array{label: string, raised: Money, gifts: int}> */
    public function byChannel(): Collection
    {
        return $this->grouped('donations.channel');
    }

    /**
     * By the region of the appeal's project, where it has one.
     *
     * A donation carries no region of its own — a donor in Accra gives to a
     * borehole in Bongo, and the report that matters is where the money is
     * going, not where it came from.
     *
     * @return Collection<int, array{label: string, raised: Money, gifts: int}>
     */
    public function byRegion(): Collection
    {
        return $this->grouped('project_locations.region', fn (Builder $q) => $q
            ->leftJoin('causes', 'causes.id', '=', 'donations.cause_id')
            ->leftJoin('project_locations', fn ($join) => $join
                ->on('project_locations.project_id', '=', 'causes.project_id')
                ->where('project_locations.is_primary', true)));
    }

    /** @return Collection<int, array{label: string, raised: Money, gifts: int}> */
    public function bySource(): Collection
    {
        return $this->grouped('donations.source');
    }

    /**
     * New donors against returning ones, in the period.
     *
     * A donor is "new" if their first ever completed gift falls in the window.
     *
     * @return array{new: int, returning: int}
     */
    public function acquisition(): array
    {
        $new = Donor::query()->whereBetween('first_donated_at', [$this->from, $this->until])->count();

        $active = (int) $this->completed()->whereNotNull('donor_id')->distinct('donor_id')->count('donor_id');

        return ['new' => $new, 'returning' => max(0, $active - $new)];
    }

    /**
     * Regular giving: what stands, what stopped, what is failing.
     *
     * Retention is the share of subscriptions that started before the window
     * and were still active at its end — the number a trustee means when they
     * ask "are the monthly donors staying?".
     *
     * @return array{active: int, failing: int, paused: int, cancelled_in_period: int, started_in_period: int, monthly_value: Money, retention: ?float}
     */
    public function recurring(): array
    {
        $active = Subscription::query()->where('status', SubscriptionStatus::Active->value);

        $monthly = (int) (clone $active)->get()->sum(fn (Subscription $s): int => match ($s->interval) {
            Subscription::INTERVAL_WEEKLY => $s->amount->toMinor() * 4,
            Subscription::INTERVAL_QUARTERLY => intdiv($s->amount->toMinor(), 3),
            Subscription::INTERVAL_ANNUALLY => intdiv($s->amount->toMinor(), 12),
            default => $s->amount->toMinor(),
        });

        $cohort = Subscription::query()->where('started_on', '<', $this->from->toDateString());
        $cohortSize = (clone $cohort)->count();
        $retained = (clone $cohort)->where(fn ($q) => $q->whereNull('ended_on')->orWhere('ended_on', '>', $this->until->toDateString()))->count();

        return [
            'active' => (clone $active)->count(),
            'failing' => Subscription::query()->where('status', SubscriptionStatus::Failing->value)->count(),
            'paused' => Subscription::query()->where('status', SubscriptionStatus::Paused->value)->count(),
            'cancelled_in_period' => Subscription::query()->whereBetween('ended_on', [$this->from->toDateString(), $this->until->toDateString()])->count(),
            'started_in_period' => Subscription::query()->whereBetween('started_on', [$this->from->toDateString(), $this->until->toDateString()])->count(),
            'monthly_value' => Money::ofMinor($monthly),
            'retention' => $cohortSize > 0 ? round($retained / $cohortSize * 100, 1) : null,
        ];
    }

    /**
     * What has settled and not yet been reconciled against a payout.
     *
     * @return array{unreconciled: int, unreconciled_amount: Money, needs_review: int, reconciled: int}
     */
    public function reconciliation(): array
    {
        $settled = DB::table('payment_transactions')
            ->where('status', 'success')
            ->where('gateway', '!=', 'offline')
            ->whereBetween('paid_at', [$this->from, $this->until]);

        $unreconciled = (clone $settled)->whereNull('reconciled_at');

        return [
            'unreconciled' => (clone $unreconciled)->count(),
            'unreconciled_amount' => Money::ofMinor((int) (clone $unreconciled)->sum('amount_paid_minor')),
            'reconciled' => (clone $settled)->whereNotNull('reconciled_at')->count(),
            'needs_review' => (int) DB::table('payment_transactions')->where('status', 'needs_review')->count(),
        ];
    }

    private function completed(): Builder
    {
        return Donation::query()
            ->where('donations.status', DonationStatus::Completed->value)
            ->whereBetween('donations.paid_at', [$this->from, $this->until]);
    }

    /**
     * @param  callable(Builder): Builder|null  $joins
     * @return Collection<int, array{label: string, raised: Money, gifts: int}>
     */
    private function grouped(string $column, ?callable $joins = null): Collection
    {
        $query = $this->completed();

        if ($joins !== null) {
            $joins($query);
        }

        return $query
            ->selectRaw("COALESCE({$column}, '—') as label, SUM(donations.amount_minor) as raised, COUNT(*) as gifts")
            ->groupBy('label')
            ->orderByDesc('raised')
            ->get()
            ->map(fn ($r): array => ['label' => (string) $r->label, 'raised' => Money::ofMinor((int) $r->raised), 'gifts' => (int) $r->gifts]);
    }
}
