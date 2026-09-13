<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Communications\MessageDispatcher;
use App\Models\ProductVariant;
use Illuminate\Console\Command;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * Tell the shop email what has run low, once.
 *
 * ── A digest, not an alarm ──────────────────────────────────────────────────
 *
 * One email a morning listing every variant at or below
 * `shop.low_stock_threshold`, sent to `contact.email_shop`. Each variant is
 * listed once — `low_stock_alerted_at` is set when it goes out and cleared
 * when stock is adjusted back above the line — so the same tote bag is not
 * reported every day until somebody gives up reading the emails.
 *
 * ── Only what sits on a shelf ───────────────────────────────────────────────
 *
 * Variants that do not track stock, and products that are not physical, are
 * not stock and are not listed.
 */
class StockAlerts extends Command
{
    protected $signature = 'scghf:stock-alerts
                            {--execute : Send the email and mark the variants as alerted}';

    protected $description = 'Email the shop about variants that have fallen to the low-stock level';

    public function handle(MessageDispatcher $dispatcher): int
    {
        $threshold = (int) setting('shop.low_stock_threshold', 5);
        $to = (string) setting('contact.email_shop', '');

        $low = ProductVariant::query()
            ->with('product')
            ->where('is_active', true)
            ->where('tracks_stock', true)
            ->whereNull('low_stock_alerted_at')
            ->whereRaw('stock_on_hand - stock_held <= ?', [$threshold])
            ->whereHas('product', fn ($q) => $q->where('product_type', 'physical')->whereNull('deleted_at'))
            ->orderBy('product_id')
            ->get();

        if ($low->isEmpty()) {
            $this->info('Nothing has fallen to the low-stock level.');

            return self::SUCCESS;
        }

        foreach ($low as $variant) {
            $this->line(sprintf('%s %s — %d left', $variant->product?->name, $variant->name ?? '', $variant->sellableQuantity()));
        }

        if (! $this->option('execute')) {
            $this->warn('DRY RUN — nothing sent. Add --execute to send.');

            return self::SUCCESS;
        }

        if (blank($to) || str_contains($to, '{{')) {
            $this->error('contact.email_shop is not set, so there is nobody to tell.');

            return self::FAILURE;
        }

        $items = $low->map(fn (ProductVariant $v): string => sprintf(
            '<li>%s%s (%s) — %d left</li>',
            e((string) $v->product?->name),
            $v->name ? ' · '.e($v->name) : '',
            e($v->sku),
            $v->sellableQuantity(),
        ))->implode("\n");

        try {
            $dispatcher->queueEmail('stock.low', $to, [
                'items' => new HtmlString('<ul>'.$items.'</ul>'),
                'threshold' => (string) $threshold,
                'admin_url' => route('filament.admin.resources.products.index', ['tableFilters[low_stock][isActive]' => 1]),
            ], [
                'idempotency_key' => 'stock.low:'.now()->toDateString(),
            ]);
        } catch (Throwable $e) {
            report($e);
            $this->error('The alert could not be queued: '.$e->getMessage());

            return self::FAILURE;
        }

        ProductVariant::whereKey($low->modelKeys())->update(['low_stock_alerted_at' => now()]);

        $this->info(sprintf('Alert queued for %d item(s) to %s.', $low->count(), $to));

        return self::SUCCESS;
    }
}
