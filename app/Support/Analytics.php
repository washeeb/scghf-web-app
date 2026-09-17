<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Which analytics script, if any, and what the CSP must allow for it.
 *
 * Three providers, one shape: a script URL, a site id, the origins the
 * Content Security Policy has to admit. `none` is the default and means
 * the site loads nothing from anybody — the built-in dashboard reads the
 * database. Whatever is chosen is written into the page as `text/plain`
 * and only becomes a script when the visitor allows the Analytics
 * category (Phase 12's cookie notice).
 */
final class Analytics
{
    public static function provider(): string
    {
        $provider = (string) setting('analytics.provider', 'none');

        return in_array($provider, ['plausible', 'umami', 'ga4'], true) && self::siteId() !== '' ? $provider : 'none';
    }

    public static function enabled(): bool
    {
        return self::provider() !== 'none';
    }

    public static function siteId(): string
    {
        return trim((string) setting('analytics.site_id', ''));
    }

    public static function scriptUrl(): string
    {
        $configured = trim((string) setting('analytics.script_url', ''));

        return match (self::provider()) {
            'plausible' => $configured ?: 'https://plausible.io/js/script.js',
            'umami' => $configured ?: 'https://cloud.umami.is/script.js',
            'ga4' => 'https://www.googletagmanager.com/gtag/js?id='.rawurlencode(self::siteId()),
            default => '',
        };
    }

    /**
     * Origins the CSP must allow for the chosen provider — the script, and
     * where it reports to.
     *
     * @return array{script: array<int, string>, connect: array<int, string>}
     */
    public static function cspOrigins(): array
    {
        if (! self::enabled()) {
            return ['script' => [], 'connect' => []];
        }

        $host = (string) parse_url(self::scriptUrl(), PHP_URL_SCHEME).'://'.(string) parse_url(self::scriptUrl(), PHP_URL_HOST);

        return match (self::provider()) {
            'ga4' => [
                'script' => ['https://www.googletagmanager.com'],
                'connect' => ['https://www.google-analytics.com', 'https://analytics.google.com', 'https://www.googletagmanager.com'],
            ],
            default => ['script' => [$host], 'connect' => [$host]],
        };
    }
}
