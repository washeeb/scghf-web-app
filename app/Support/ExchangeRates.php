<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * How many cedis one foreign unit buys today — Wave 2 (1.8, display only).
 *
 * ── Where the numbers come from ─────────────────────────────────────────────
 *
 * A daily fetch (`scghf:refresh-rates`, 05:30) from a keyless public feed,
 * stored in the cache as a plain array with the time it was fetched. A
 * request never calls the feed: it reads the cache, and if the cache is
 * empty it reads the manual rates from Settings → Currency, and if those
 * are empty there is no rate and no approximate figure is shown. The
 * foundation can also choose manual rates over the feed outright — the
 * Bank of Ghana's published rate, typed in when the treasurer checks it.
 *
 * ── What the numbers are for ────────────────────────────────────────────────
 *
 * A "≈ £8" beside GH₵ 150 for a donor who thinks in pounds. Never for
 * charging: every gift is taken in GHS, the receipt says GHS, the ledger
 * is GHS. The approximate sign is not decoration — it is the promise the
 * figure makes.
 *
 * Rates are kept as integers of one ten-thousandth (12.3456 → 123456) so
 * a conversion is integer arithmetic on pesewas, and the Money class's
 * rule against floats holds all the way through.
 */
final class ExchangeRates
{
    public const CURRENCIES = ['USD', 'GBP', 'EUR'];

    public const CACHE_KEY = 'currency:rates';

    public const SCALE = 10_000;

    public const SOURCE_URL = 'https://open.er-api.com/v6/latest/GHS';

    /**
     * The current rates: GHS per one unit, scaled by SCALE.
     *
     * @return array{rates: array<string, int>, fetched_at: string|null, source: string}|null
     */
    public function current(): ?array
    {
        $manual = $this->manual();

        if ((string) setting('currency.rate_source', 'api') === 'manual') {
            return $manual;
        }

        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached) && isset($cached['rates']) && $cached['rates'] !== []) {
            return $cached;
        }

        return $manual;
    }

    /** The scaled rate for one currency, or null when there is none. */
    public function rate(string $currency): ?int
    {
        $current = $this->current();

        return $current['rates'][strtoupper($currency)] ?? null;
    }

    /**
     * Ask the feed and store what it says. Returns the stored set, or null
     * when the feed failed — in which case the last good set stays.
     *
     * @return array{rates: array<string, int>, fetched_at: string, source: string}|null
     */
    public function refresh(): ?array
    {
        try {
            $response = Http::timeout(8)->acceptJson()->get(self::SOURCE_URL);

            if (! $response->ok()) {
                Log::warning('Exchange-rate feed answered '.$response->status().'; keeping the last rates.');

                return null;
            }

            $body = $response->json();
            $quoted = (array) ($body['rates'] ?? []);
            $rates = [];

            foreach (self::CURRENCIES as $currency) {
                // The feed quotes how many foreign units one cedi buys; we
                // keep the inverse — cedis per foreign unit — because that is
                // the number a person recognises ("about twelve cedis to the
                // dollar") and the one the manual settings hold.
                $perCedi = $quoted[$currency] ?? null;

                if (! is_numeric($perCedi) || (float) $perCedi <= 0) {
                    continue;
                }

                $rates[$currency] = (int) round(self::SCALE / (float) $perCedi);
            }

            if ($rates === []) {
                Log::warning('Exchange-rate feed carried none of the currencies we show; keeping the last rates.');

                return null;
            }

            $set = ['rates' => $rates, 'fetched_at' => now()->toIso8601String(), 'source' => 'api'];

            // Forever, on purpose: a feed that is down for a week should
            // leave last week's figure on the page rather than none. The
            // page says how old it is.
            Cache::forever(self::CACHE_KEY, $set);

            return $set;
        } catch (Throwable $e) {
            Log::warning('Exchange-rate feed unreachable: '.$e->getMessage());

            return null;
        }
    }

    /** @return array{rates: array<string, int>, fetched_at: string|null, source: string}|null */
    private function manual(): ?array
    {
        $rates = [];

        foreach (self::CURRENCIES as $currency) {
            $value = setting('currency.rate_'.strtolower($currency));

            if (is_numeric($value) && (float) $value > 0) {
                $rates[$currency] = (int) round((float) $value * self::SCALE);
            }
        }

        return $rates === [] ? null : ['rates' => $rates, 'fetched_at' => null, 'source' => 'manual'];
    }
}
