<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\ExchangeRates;
use App\Support\SiteCache;
use Illuminate\Console\Command;

/**
 * Fetch today's rates for the approximate figures. Daily at 05:30; a
 * failure keeps yesterday's, and says so.
 */
class RefreshExchangeRates extends Command
{
    protected $signature = 'scghf:refresh-rates';

    protected $description = 'Fetch the day’s exchange rates for the approximate foreign-currency figures';

    public function handle(ExchangeRates $rates): int
    {
        $set = $rates->refresh();

        if ($set === null) {
            $this->warn('The rate feed did not answer; the last rates stay in place.');

            return self::FAILURE;
        }

        // Pages carry the figures, so the page cache is stale now.
        SiteCache::bump();
        $this->callSilently('scghf:cache-clear');

        foreach ($set['rates'] as $currency => $scaled) {
            $this->line(sprintf('  1 %s = GH₵ %s', $currency, number_format($scaled / ExchangeRates::SCALE, 4)));
        }

        $this->info('Rates refreshed at '.$set['fetched_at'].'.');

        return self::SUCCESS;
    }
}
