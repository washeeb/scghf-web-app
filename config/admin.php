<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Admin panel
|--------------------------------------------------------------------------
|
| The Filament panel the foundation's staff use to run the site.
|
| Every key here has been sitting in .env.example since Phase 2 with nothing
| reading it — `ADMIN_PATH`, `ADMIN_2FA_REQUIRED`, `ADMIN_SESSION_TIMEOUT`.
| Phase 4 is the phase that consumes them, so they are consumed rather than
| documented for a third time.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Where the panel lives
    |--------------------------------------------------------------------------
    |
    | `/admin` is the first path a scanner tries, and `/administrator` is the
    | second. Moving it is not security on its own — the panel is protected by
    | authentication, staff-only access and mandatory 2FA — but it removes this
    | site from the automated sweeps that go looking for a login form to spray
    | credentials at, and that is most of the traffic a small site's login page
    | ever sees.
    |
    | The default is foundation-specific rather than a generic word, because
    | `manage`, `backend`, `panel`, `console`, `dashboard` and `office` are all
    | in the same wordlists `admin` is. A hyphenated organisation name is not.
    |
    | Changing it invalidates every bookmarked admin URL, so it is a decision to
    | make once, before staff have bookmarks.
    |
    | ⚠ AN EMPTY VALUE IS REFUSED, and that is not pedantry.
    |
    | `ADMIN_PATH=` in a .env — a key somebody cleared rather than deleted —
    | would mount the whole admin panel at `/`, where it would shadow the public
    | site. The home page would become a login form, silently, on deploy. The
    | fallback below is what stops a blank line in a config file taking the
    | website down.
    */
    'path' => (static function (): string {
        $path = trim((string) env('ADMIN_PATH', 'scghf-office'), " \t\n\r/");

        /*
         * Paths that would collide with something the application already
         * serves. Mounting the panel on any of these does not fail loudly — it
         * quietly wins or loses a routing race, and which one depends on
         * registration order.
         */
        $reserved = ['', 'webhooks', 'storage', 'livewire', 'up', 'api'];

        return in_array($path, $reserved, true) ? 'scghf-office' : $path;
    })(),

    /*
    |--------------------------------------------------------------------------
    | Two-factor authentication
    |--------------------------------------------------------------------------
    |
    | Blueprint §7.1: required for every admin role, not offered.
    |
    | An administrator here can read beneficiary case files, export donor
    | records and issue refunds. A stolen password on one of those accounts is
    | the worst day this foundation has, and a password is the credential most
    | likely to be reused from somewhere that has already been breached.
    |
    | ⚠ Setting this to false does not merely relax a policy — it removes the
    | only barrier between a leaked password and every record the foundation
    | holds. It exists as a switch solely so a locked-out administrator can be
    | recovered by somebody with server access, and it should be switched back
    | the same hour.
    */
    'require_two_factor' => (bool) env('ADMIN_2FA_REQUIRED', true),

    /*
    |--------------------------------------------------------------------------
    | Session
    |--------------------------------------------------------------------------
    |
    | Minutes of inactivity before a staff session ends. Deliberately shorter
    | than the public session lifetime: an admin panel left open on a shared
    | office machine is a different exposure from a donor's own phone.
    */
    'session_timeout' => (int) env('ADMIN_SESSION_TIMEOUT', 60),

    /*
     * Minutes since sign-in after which a staff session ends regardless of
     * activity. An idle timeout alone never ends a session on a machine that
     * keeps the tab moving; twelve hours ends the working day.
     */
    'absolute_timeout' => (int) env('ADMIN_ABSOLUTE_TIMEOUT', 720),

    /*
     * One live session per staff account. Signing in anywhere ends every
     * other session — so a password used from two places at once is
     * noticed by the person whose screen goes back to the login form.
     * Off by default: a coordinator on a laptop and a phone is normal.
     */
    'single_session' => (bool) env('ADMIN_SINGLE_SESSION', false),

    /*
    |--------------------------------------------------------------------------
    | IP allowlist
    |--------------------------------------------------------------------------
    |
    | Comma-separated addresses or CIDR ranges that may reach the panel at
    | all. Empty = anywhere (the default: the foundation's staff work from
    | phones on mobile data with addresses that change daily). Behind
    | Cloudflare, TRUSTED_PROXIES must be set or every visitor is Cloudflare.
    */
    'ip_allowlist' => array_values(array_filter(array_map('trim', explode(',', (string) env('ADMIN_IP_ALLOWLIST', ''))))),

    /*
    |--------------------------------------------------------------------------
    | Branding
    |--------------------------------------------------------------------------
    |
    | Names and colours come from the CMS layer at runtime, per CLAUDE.md's rule
    | that no real content is hardcoded. These are the KEYS to read, not the
    | values — the panel resolves them through `setting()` and the theme tokens,
    | so renaming the foundation is an edit in the admin panel and not a deploy.
    */
    'branding' => [
        'name_setting' => 'general.short_name',
        'legal_name_setting' => 'general.legal_name',

        // Theme tokens, resolved per light/dark from `theme_settings`.
        'primary_token' => 'brand-primary',
        'secondary_token' => 'brand-secondary',
    ],
];
