<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\DonationStatus;
use App\Enums\OrderStatus;
use App\Models\ContactMessage;
use App\Models\Donation;
use App\Models\Order;
use App\Models\ProductVariant;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The things somebody has to do something about.
 *
 * ── Every number here is a queue, not a statistic ───────────────────────────
 *
 * "Total donations" is interesting. "Four donations need review" is work. This
 * widget only carries the second kind, because a dashboard of impressive
 * totals is one people stop reading, and the point of a dashboard on a small
 * foundation's admin panel is that somebody notices the unanswered enquiry.
 *
 * ── Zero is shown in grey, not hidden ───────────────────────────────────────
 *
 * A tile that disappears when it is empty makes "nothing to do" and "the tile
 * is broken" look identical. Grey zero is the reassurance.
 *
 * ── Each tile links to the work ─────────────────────────────────────────────
 *
 * A number nobody can click is a number somebody has to go and find, and they
 * will find it by opening four screens.
 */
class NeedsAttention extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user !== null && (
            $user->can('donations.view')
            || $user->can('orders.view')
            || $user->can('contact.view')
        );
    }

    protected function getStats(): array
    {
        return collect([
            $this->donationsNeedingReview(),
            $this->newMessages(),
            $this->pendingOrders(),
            $this->lowStock(),
        ])->filter()->values()->all();
    }

    /**
     * Donations the webhook could not reconcile.
     *
     * ⚠ The most important number on this page. A donation lands in
     * `needs_review` when the amount or currency the webhook reported did not
     * match what was expected — which is either a bug or somebody tampering
     * with a payment, and in both cases it is money that must not be quietly
     * marked complete.
     */
    private function donationsNeedingReview(): ?Stat
    {
        if (! auth()->user()?->can('donations.view')) {
            return null;
        }

        $count = Donation::query()->where('status', DonationStatus::NeedsReview->value)->count();

        return Stat::make(__('Donations to check'), (string) $count)
            ->description($count === 0
                ? __('Nothing unreconciled')
                : __('The amount Paystack reported did not match what we expected'))
            ->color($count === 0 ? 'gray' : 'danger')
            ->icon('heroicon-o-exclamation-triangle');
    }

    private function newMessages(): ?Stat
    {
        if (! auth()->user()?->can('contact.view')) {
            return null;
        }

        $count = ContactMessage::query()->where('status', 'new')->count();

        $oldest = ContactMessage::query()
            ->whereIn('status', ['new', 'assigned'])
            ->oldest('created_at')
            ->value('created_at');

        return Stat::make(__('Unanswered enquiries'), (string) $count)
            ->description($oldest === null
                ? __('Nothing waiting')
                : __('Oldest has waited :time', ['time' => $oldest->diffForHumans(syntax: true)]))
            ->color(match (true) {
                $count === 0 => 'gray',
                $oldest?->lt(now()->subWeek()) => 'danger',
                default => 'warning',
            })
            ->icon('heroicon-o-inbox');
    }

    private function pendingOrders(): ?Stat
    {
        if (! auth()->user()?->can('orders.view')) {
            return null;
        }

        $count = Order::query()
            ->whereIn('status', [OrderStatus::Paid->value, OrderStatus::Processing->value])
            ->count();

        return Stat::make(__('Orders to send'), (string) $count)
            ->description($count === 0 ? __('Nothing to pack') : __('Paid and waiting to be sent'))
            ->color($count === 0 ? 'gray' : 'warning')
            ->icon('heroicon-o-shopping-bag');
    }

    /**
     * Stock about to run out.
     *
     * Sellable quantity, not stock on hand: goods held for somebody sitting on
     * a payment page are already spoken for, and counting them is how two
     * customers buy the last mug.
     */
    private function lowStock(): ?Stat
    {
        if (! auth()->user()?->can('products.view')) {
            return null;
        }

        $threshold = (int) setting('shop.low_stock_threshold', 5);

        $count = ProductVariant::query()
            ->where('is_active', true)
            ->where('tracks_stock', true)
            ->where('allow_backorder', false)
            ->whereRaw('(stock_on_hand - stock_held) <= ?', [$threshold])
            ->count();

        return Stat::make(__('Low stock'), (string) $count)
            ->description($count === 0
                ? __('Everything in stock')
                : __('At or below :threshold left', ['threshold' => $threshold]))
            ->color($count === 0 ? 'gray' : 'warning')
            ->icon('heroicon-o-archive-box');
    }
}
