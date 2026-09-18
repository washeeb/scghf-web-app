<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Performance on shared hosting
|--------------------------------------------------------------------------
|
| No Redis, no object cache, PHP seconds and MySQL connections metered by
| the host. What is cached, where, and for how long. Every key below is
| read by App\Support\SiteCache or App\Http\Middleware\CachePublicPage.
|
*/

return [

    /*
    | Fragment cache — menus, the announcement, the policy links, the
    | homepage blocks. Lives in the default cache store under one
    | "generation" number that every content save bumps, so nothing is
    | ever served stale and nothing has to know which key to forget.
    | The TTL is the safety net for a change that reached the database by
    | a route the observers do not see (a raw query, a restore).
    */
    'fragments' => [
        'store' => env('FRAGMENT_CACHE_STORE', 'file'),
        'ttl' => (int) env('FRAGMENT_CACHE_TTL', 3600),
    ],

    /*
    | Full-page cache for anonymous visitors.
    |
    | A public GET with no session state, no query string but `page`, and
    | a 200 HTML answer is stored whole and served without touching the
    | database. The per-request CSP nonce and the CSRF token are swapped
    | into the stored body on every hit, so the nonce policy and every
    | form keep working. Varies on the `scghf_*` cookies (theme, consent,
    | dismissed announcements). Never for anything signed in, personal, or
    | transactional — the `except` list, and the middleware's own rules.
    |
    | Its own store, file by default: a page is 30–80 KB and a hit that
    | costs a MySQL round trip to a `cache` table is a cheaper miss, not a
    | hit. One file per page variant; a few hundred at most.
    */
    'page_cache' => [
        'enabled' => (bool) env('PAGE_CACHE_ENABLED', true),
        'store' => env('PAGE_CACHE_STORE', 'pages'),
        'ttl' => (int) env('PAGE_CACHE_TTL', 600),

        // Request paths never cached, as `Request::is()` patterns. The admin
        // path is added at runtime from ADMIN_PATH.
        'except' => [
            'basket', 'basket/*',
            'checkout', 'checkout/*',
            'account/*', 'profile', 'security', 'security/*', 'privacy', 'giving', 'giving/*',
            'login', 'register', 'logout', 'forgot-password', 'reset-password/*',
            'verify-email', 'verify-email/*', 'two-factor-challenge',
            'donate/*',            // pay, thank-you, status, callback — personal; the form itself (`donate`) is cached
            'shop/orders/*',
            'payments/*',
            'tickets/*', 'receipts/*', 'invoices/*', 'downloads/*', 'reports/*/download',
            'newsletter/*',
            'announcements/*',
            'events/registrations/*',
            'volunteer/applications/*',
            'pages/*/preview',
            'manual/*',
            'search',
            'sitemap.xml', 'sitemaps/*', 'robots.txt',
            'up', 'webhooks/*', 'csp-report', 't/*',
        ],
    ],

];
