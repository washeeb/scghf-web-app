<?php

declare(strict_types=1);

use App\Support\Settings;

if (! function_exists('setting')) {
    /**
     * A CMS setting, cast to its declared type.
     *
     *     setting('contact.phone_primary')
     *     setting('donations.presets', [5000, 10000])
     *
     * Called with no arguments it returns the repository itself, so
     * `setting()->group('contact')` works in a Blade layout.
     *
     * An unfilled {{PLACEHOLDER}} counts as absent and yields $default — a
     * donor must never be shown a raw token.
     */
    function setting(?string $key = null, mixed $default = null): mixed
    {
        $settings = app(Settings::class);

        return $key === null ? $settings : $settings->get($key, $default);
    }
}
