<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Feature flags
|--------------------------------------------------------------------------
|
| Modules deferred in PHASE-1-BLUEPRINT.md §12c ship behind flags, OFF by
| default, so partially built features cannot leak into production. Read with
| config('features.x'), never with env() outside this file — env() returns null
| once config is cached, which on a live server means every flag silently
| becomes false.
|
*/

return [
    'shop' => env('FEATURE_SHOP', true),
    'donations' => env('FEATURE_DONATIONS', true),
    'recurring_giving' => env('FEATURE_RECURRING_GIVING', true),
    'mobile_money' => env('FEATURE_MOBILE_MONEY', true),
    'volunteers' => env('FEATURE_VOLUNTEERS', true),
    'events' => env('FEATURE_EVENTS', true),
    'event_ticketing' => env('FEATURE_EVENT_TICKETING', false),
    'p2p_fundraising' => env('FEATURE_P2P_FUNDRAISING', false),
    'sponsorship' => env('FEATURE_SPONSORSHIP', true),
    'blog_comments' => env('FEATURE_BLOG_COMMENTS', false),
    'prayer_requests' => env('FEATURE_PRAYER_REQUESTS', true),
    'multilingual' => env('FEATURE_MULTILINGUAL', false),
    'site_search' => env('FEATURE_SITE_SEARCH', true),
    'dark_mode' => env('FEATURE_DARK_MODE', true),
    // Built in Wave 1: the manifest, the icons, the worker and /offline.
    'pwa_offline' => env('FEATURE_PWA_OFFLINE', true),
    // Built (Wave 2): the channel, the templates, the opt-in, the webhook.
    // Off until Meta has verified the business and approved the templates —
    // a genuine deferral on a real dependency, not an empty flag; the launch
    // check says what is missing when it is on.
    'whatsapp' => env('FEATURE_WHATSAPP', false),
];
