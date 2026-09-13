<?php

declare(strict_types=1);

namespace App\Shop;

use App\Enums\DonationStatus;
use App\Models\Product;
use App\ValueObjects\Money;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The shop's numbers.
 *
 * ── Paid orders, by the date the money arrived ──────────────────────────────
 *
 * Every figure counts orders whose payment settled in the window, by
 * `paid_at`. A pending order is not a sale; a refunded one is a sale that
 * was reversed, and the refund is shown against the period it was paid in.
 *
 * ── Goods, not gifts ────────────────────────────────────────────────────────
 *
 * A "sponsor a meal" line and the gift added at checkout are donations:
 * they are in GivingReports already, and counting them here as well would
 * count them twice in "total funds raised". Shop revenue is goods, delivery
 * and discounts; the gifts are subtracted out.
 *
 * ── Net proceeds ────────────────────────────────────────────────────────────
 *
 * Goods revenue less the gateway's fee. The foundation does not record a
 * cost price for merchandise, so this is what reached the bank for the
 * goods, not a profit — and the stock valuation, likewise, is at selling
 * price. Both say so on the page.
 */
final class ShopReports
{
    /** Paid and not reversed. A refunded order is reported on its own line. */
    private const PAID = ['paid', 'processing', 'packed', 'shipped', 'out_for_delivery', 'delivered', 'collected', 'completed'];

    public function __construct(private readonly Carbon $from, private readonly Carbon $until) {}

    public static function between(Carbon $from, Carbon $until): self
    {
        return new self($from->copy()->startOfDay(), $until->copy()->endOfDay());
    }

    /**
     * @return array{orders: int, goods: Money, delivery: Money, discounts: Money, gifts: Money, fees: Money, net: Money, refunded: Money, average: Money}
     */
    public function summary(): array
    {
        $row = $this->paidOrders()
            ->selectRaw('COUNT(*) as orders, COALESCE(SUM(subtotal_minor), 0) as subtotal, COALESCE(SUM(shipping_minor), 0) as delivery, COALESCE(SUM(discount_minor), 0) as discounts, COALESCE(SUM(donation_minor), 0) as added_gifts, COALESCE(SUM(fee_minor), 0) as fees')
            ->first();

        $giftLines = (int) $this->paidLines()->where('products.product_type', Product::TYPE_DONATION)->sum('order_items.line_total_minor');

        $goods = (int) $row->subtotal - $giftLines;
        $orders = (int) $row->orders;
        $net = $goods + (int) $row->delivery - (int) $row->discounts - (int) $row->fees;

        $refunded = (int) DB::table('orders')
            ->where('status', 'refunded')
            ->whereBetween('paid_at', [$this->from, $this->until])
            ->sum(DB::raw('subtotal_minor + shipping_minor - discount_minor'));

        return [
            'orders' => $orders,
            'goods' => Money::ofMinor($goods),
            'delivery' => Money::ofMinor((int) $row->delivery),
            'discounts' => Money::ofMinor((int) $row->discounts),
            'gifts' => Money::ofMinor($giftLines + (int) $row->added_gifts),
            'fees' => Money::ofMinor((int) $row->fees),
            'net' => Money::ofMinor($net),
            'refunded' => Money::ofMinor($refunded),
            'average' => Money::ofMinor($orders > 0 ? intdiv($goods, $orders) : 0),
        ];
    }

    /**
     * @param  'day'|'week'|'month'  $granularity
     * @return Collection<int, array{period: string, goods: Money, orders: int}>
     */
    public function byPeriod(string $granularity = 'day'): Collection
    {
        $format = match ($granularity) {
            'month' => '%Y-%m',
            'week' => '%x-W%v',
            default => '%Y-%m-%d',
        };

        return $this->paidLines()
            ->where('products.product_type', '!=', Product::TYPE_DONATION)
            ->selectRaw("DATE_FORMAT(orders.paid_at, '{$format}') as period, SUM(order_items.line_total_minor) as goods, COUNT(DISTINCT orders.id) as orders")
            ->groupBy('period')
            ->orderBy('period')
            ->get()
            ->map(fn ($r): array => ['period' => (string) $r->period, 'goods' => Money::ofMinor((int) $r->goods), 'orders' => (int) $r->orders]);
    }

    /** @return Collection<int, array{label: string, goods: Money, quantity: int}> */
    public function byProduct(): Collection
    {
        return $this->grouped('order_items.product_name');
    }

    /** @return Collection<int, array{label: string, goods: Money, quantity: int}> */
    public function byCategory(): Collection
    {
        return $this->grouped('product_categories.name', fn ($q) => $q->leftJoin('product_categories', 'product_categories.id', '=', 'products.product_category_id'));
    }

    /** By units sold, which is what "best seller" means to somebody reordering stock. */
    public function bestSellers(int $limit = 10): Collection
    {
        return $this->grouped('order_items.product_name', orderBy: 'quantity')->take($limit);
    }

    /**
     * Revenue attributed to each appeal a product supports — goods only.
     * The gifts are in the giving reports under the same appeal.
     *
     * @return Collection<int, array{label: string, goods: Money, quantity: int}>
     */
    public function byCause(): Collection
    {
        return $this->grouped('causes.title', fn ($q) => $q->leftJoin('causes', 'causes.id', '=', 'products.cause_id'));
    }

    /**
     * What is on the shelf, at selling price.
     *
     * @return array{units: int, value: Money, variants: int}
     */
    public function stockValuation(): array
    {
        $row = DB::table('product_variants')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->whereNull('product_variants.deleted_at')
            ->whereNull('products.deleted_at')
            ->where('product_variants.tracks_stock', true)
            ->where('products.product_type', Product::TYPE_PHYSICAL)
            ->selectRaw('COALESCE(SUM(stock_on_hand), 0) as units, COALESCE(SUM(stock_on_hand * price_minor), 0) as value, COUNT(*) as variants')
            ->first();

        return [
            'units' => (int) $row->units,
            'value' => Money::ofMinor((int) $row->value),
            'variants' => (int) $row->variants,
        ];
    }

    /**
     * Donations plus net shop proceeds, for the period. The one figure a
     * trustee asks for, with the two halves shown so nobody adds them again.
     *
     * @return array{donations: Money, shop: Money, total: Money}
     */
    public function fundsRaised(): array
    {
        $donations = (int) DB::table('donations')
            ->where('status', DonationStatus::Completed->value)
            ->whereBetween('paid_at', [$this->from, $this->until])
            ->sum('amount_minor');

        $shop = $this->summary()['net']->toMinor();

        return [
            'donations' => Money::ofMinor($donations),
            'shop' => Money::ofMinor($shop),
            'total' => Money::ofMinor($donations + $shop),
        ];
    }

    private function paidOrders(): Builder
    {
        return DB::table('orders')
            ->whereIn('orders.status', self::PAID)
            ->whereBetween('orders.paid_at', [$this->from, $this->until]);
    }

    private function paidLines(): Builder
    {
        return $this->paidOrders()
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->leftJoin('products', 'products.id', '=', 'order_items.product_id');
    }

    /**
     * @param  callable(Builder): mixed|null  $joins
     * @return Collection<int, array{label: string, goods: Money, quantity: int}>
     */
    private function grouped(string $column, ?callable $joins = null, string $orderBy = 'goods'): Collection
    {
        $query = $this->paidLines()->where(fn ($q) => $q->whereNull('products.product_type')->orWhere('products.product_type', '!=', Product::TYPE_DONATION));

        if ($joins !== null) {
            $joins($query);
        }

        return $query
            ->selectRaw("COALESCE({$column}, '—') as label, SUM(order_items.line_total_minor) as goods, SUM(order_items.quantity) as quantity")
            ->groupBy('label')
            ->orderByDesc($orderBy)
            ->get()
            ->map(fn ($r): array => ['label' => (string) $r->label, 'goods' => Money::ofMinor((int) $r->goods), 'quantity' => (int) $r->quantity]);
    }
}
