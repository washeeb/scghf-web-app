/**
 * Registers the service worker and offers "add to home screen" — quietly.
 *
 * Both are enhancements. The worker only exists when the site says so
 * (`data-pwa` on <body>, from the feature flag); with the flag off, an
 * already-installed worker is fetched once more and unregisters itself.
 *
 * The install prompt is never forced. When the browser says the site is
 * installable, a small "Add to your phone" link appears in the footer
 * (the element already exists, hidden) and the browser's own prompt runs
 * on click. No banners, no modals: a donor on a metered connection did not
 * come here to be asked to install anything.
 */
export function initPwa() {
    if (!('serviceWorker' in navigator)) return;

    const enabled = document.body.dataset.pwa === 'on';

    if (!enabled) {
        navigator.serviceWorker.getRegistrations().then((list) => list.forEach((r) => r.update()));

        return;
    }

    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(() => {
            // An unregistered worker is the pre-PWA site, which works.
        });
    });

    let deferred = null;
    const link = document.querySelector('[data-pwa-install]');

    window.addEventListener('beforeinstallprompt', (event) => {
        event.preventDefault();
        deferred = event;

        if (link) link.hidden = false;
    });

    link?.addEventListener('click', async (event) => {
        event.preventDefault();

        if (!deferred) return;

        deferred.prompt();
        await deferred.userChoice;
        deferred = null;
        link.hidden = true;
    });

    window.addEventListener('appinstalled', () => {
        if (link) link.hidden = true;
    });
}
