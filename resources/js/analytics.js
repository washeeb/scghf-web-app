/**
 * Conversion events, provider-agnostic.
 *
 * `scghfTrack(name, props)` sends to whichever analytics script is loaded
 * — Plausible, Umami or GA4 — and to none when none is, which is the
 * default. The nine events the site sends are named here so the dashboard
 * in whichever tool, and this file, agree:
 *
 *   donation_started, donation_completed {value, currency, cause, regular},
 *   recurring_started, add_to_cart, checkout_started, purchase {value},
 *   newsletter_signup, volunteer_application, contact_submitted
 *
 * Server-known conversions are rendered as `[data-track-event]` on the
 * page that confirms them (a thank-you page, a flash after a form) and
 * fired once — `data-track-once` keys a sessionStorage guard so a
 * refresh of a thank-you page is not a second donation.
 */
export function track(name, props = {}) {
    try {
        if (typeof window.plausible === 'function') {
            window.plausible(name, { props });
        } else if (window.umami && typeof window.umami.track === 'function') {
            window.umami.track(name, props);
        } else if (typeof window.gtag === 'function') {
            window.gtag('event', name, props);
        }
    } catch {
        // Analytics must never break a page.
    }
}

export function initAnalytics() {
    window.scghfTrack = track;

    // Server-rendered conversions, once per key.
    document.querySelectorAll('[data-track-event]').forEach((el) => {
        const key = 'scghf.tracked.' + (el.dataset.trackOnce || el.dataset.trackEvent);

        try {
            if (window.sessionStorage.getItem(key)) {
                return;
            }
            window.sessionStorage.setItem(key, '1');
        } catch {
            // No storage: fire anyway, once is best effort.
        }

        let props = {};
        try {
            props = JSON.parse(el.dataset.trackProps || '{}');
        } catch {
            props = {};
        }

        // The analytics script may load a moment after consent; give it a beat.
        window.setTimeout(() => track(el.dataset.trackEvent, props), 400);
    });

    // Client-side intents: a form submitted, a button pressed.
    document.querySelectorAll('[data-track-on]').forEach((el) => {
        const [on, name] = (el.dataset.trackOn || '').split(':');

        if (!on || !name) {
            return;
        }

        el.addEventListener(on, () => {
            let props = {};
            try {
                props = JSON.parse(el.dataset.trackProps || '{}');
            } catch {
                props = {};
            }
            track(name, props);
        });
    });
}
