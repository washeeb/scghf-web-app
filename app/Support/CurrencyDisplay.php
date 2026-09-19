<?php

declare(strict_types=1);

namespace App\Support;

use App\ValueObjects\Money;
use Illuminate\Http\Request;

/**
 * The visitor's second currency: which one, and what a cedi amount is in it.
 *
 * The foundation chooses the default under Settings → Currency (none, or
 * one of USD, GBP, EUR); a visitor can pick another in the footer, which
 * sets the `scghf_currency` cookie — unencrypted, like the theme cookie,
 * because the page cache keys on it and a value nobody can read is a key
 * nobody can share. The gift is still taken in cedis; this only adds an
 * "≈ £8" beside the "GH₵ 150" and never replaces it.
 */
final class CurrencyDisplay
{
    public const COOKIE = 'scghf_currency';

    public const COOKIE_MINUTES = 60 * 24 * 365;

    public function __construct(private readonly ExchangeRates $rates) {}

    /** @return array<string, string> code => label, for the picker */
    public static function options(): array
    {
        return ['' => __('Cedis only'), 'USD' => __('US dollars (≈)'), 'GBP' => __('Pounds (≈)'), 'EUR' => __('Euros (≈)')];
    }

    /** The currency in effect for this request, or null for cedis only. */
    public function active(?Request $request = null): ?string
    {
        $request ??= request();
        $chosen = strtoupper((string) $request->cookie(self::COOKIE, ''));

        if ($chosen === 'NONE') {
            return null;
        }

        if (in_array($chosen, ExchangeRates::CURRENCIES, true)) {
            return $chosen;
        }

        $default = strtoupper((string) setting('currency.display_default', ''));

        return in_array($default, ExchangeRates::CURRENCIES, true) ? $default : null;
    }

    /** The cedi amount in the active currency, or null when there is no rate or no currency. */
    public function convert(Money $amount, ?string $currency = null): ?Money
    {
        $currency ??= $this->active();

        if ($currency === null || $amount->currency !== 'GHS') {
            return null;
        }

        $rate = $this->rates->rate($currency);

        if ($rate === null || $rate <= 0) {
            return null;
        }

        // pesewas × SCALE / (cedis-per-unit × SCALE) = foreign minor units;
        // integer division with rounding, never a float on the money.
        $minor = intdiv($amount->toMinor() * ExchangeRates::SCALE + intdiv($rate, 2), $rate);

        return Money::ofMinor($minor, $currency);
    }

    /** "≈ $85.20", or null. Whole units above 100 — "≈ $1,234" — because the figure is approximate. */
    public function approx(Money $amount, ?string $currency = null): ?string
    {
        $converted = $this->convert($amount, $currency);

        if ($converted === null) {
            return null;
        }

        $formatted = $converted->format();

        if (abs($converted->toMinor()) >= 100_00) {
            $formatted = (string) preg_replace('/\.\d{2}$/', '', $formatted);
        }

        return '≈ '.$formatted;
    }

    /** When the rate was last fetched, for the small print. */
    public function asOf(): ?string
    {
        return $this->rates->current()['fetched_at'] ?? null;
    }
}
