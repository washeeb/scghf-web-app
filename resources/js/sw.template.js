/*
 * The service worker. Served by PwaController as /sw.js with the three
 * placeholders below filled in from configuration — the cache name, the
 * precache list (the offline page, the built CSS and JS, the body font) and
 * the paths that are NEVER cached (everything that takes money, holds a
 * basket, belongs to a signed-in person, or is the admin).
 *
 * Strategy, deliberately simple:
 *   - navigations (a page): network first; on failure, the cached copy of
 *     that page if there is one, else /offline
 *   - hashed build assets and fonts: cache first (their names change when
 *     they change)
 *   - images from this origin: network first, cached copy on failure
 *   - anything on the never-cache list, any non-GET, any other origin:
 *     the network, untouched
 *
 * When the feature flag is off the worker unregisters itself and clears its
 * caches on the next visit, so switching it off in the admin is enough.
 */

const CACHE = __CACHE_NAME__;
const PRECACHE = __PRECACHE__;
const NEVER = __NEVER_CACHE__;
const ENABLED = __ENABLED__;

function matchesPattern(path, pattern) {
    // Laravel's Request::is() semantics: '*' matches anything, the rest is literal.
    const escaped = pattern.split('*').map((s) => s.replace(/[.+?^${}()|[\]\\]/g, '\\$&')).join('.*');

    return new RegExp('^/?' + escaped + '$').test(path.replace(/^\//, ''));
}

function neverCache(url) {
    const path = url.pathname.replace(/^\//, '');

    return NEVER.some((pattern) => matchesPattern(path, pattern));
}

self.addEventListener('install', (event) => {
    if (!ENABLED) return;

    event.waitUntil(
        caches.open(CACHE).then((cache) => cache.addAll(PRECACHE)).then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        (async () => {
            const names = await caches.keys();
            await Promise.all(names.filter((n) => n !== CACHE).map((n) => caches.delete(n)));

            if (!ENABLED) {
                await Promise.all(names.map((n) => caches.delete(n)));
                await self.registration.unregister();

                return;
            }

            await self.clients.claim();
        })(),
    );
});

self.addEventListener('fetch', (event) => {
    if (!ENABLED) return;

    const request = event.request;
    const url = new URL(request.url);

    if (request.method !== 'GET' || url.origin !== self.location.origin || neverCache(url)) {
        return;
    }

    // A signed-in visitor's pages are personal; the session cookie is the
    // only signal the worker has, and it cannot read it — so pages are
    // always network-first and a cached copy is only ever served when the
    // network is gone.
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request)
                .then((response) => {
                    if (response.ok && url.search === '') {
                        const copy = response.clone();
                        caches.open(CACHE).then((cache) => cache.put(request, copy));
                    }

                    return response;
                })
                .catch(async () => (await caches.match(request)) || (await caches.match('/offline')) || Response.error()),
        );

        return;
    }

    const isHashedAsset = url.pathname.startsWith('/build/') || url.pathname.startsWith('/fonts/');

    if (isHashedAsset) {
        event.respondWith(
            caches.match(request).then((hit) => hit || fetch(request).then((response) => {
                if (response.ok) {
                    const copy = response.clone();
                    caches.open(CACHE).then((cache) => cache.put(request, copy));
                }

                return response;
            })),
        );

        return;
    }

    if (request.destination === 'image') {
        event.respondWith(
            fetch(request)
                .then((response) => {
                    if (response.ok) {
                        const copy = response.clone();
                        caches.open(CACHE).then((cache) => cache.put(request, copy));
                    }

                    return response;
                })
                .catch(() => caches.match(request).then((hit) => hit || Response.error())),
        );
    }
});
