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
    | `/admin` is the first path a scanner tries, and the second is `/administrator`.
    | Moving it is not security on its own — the panel is protected by
    | authentication, staff-only access and mandatory 2FA — but it removes this
    | site from the automated sweeps that go looking for a login form to spray
    | credentials at, and that is most of the traffic.
    |
    | Changing it invalidates every bookmarked admin URL, so it is a decision to
    | make once, before launch, rather than later.
    */
    'path' => trim((string) env('ADMIN_PATH', 'admin'), '/'),

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
