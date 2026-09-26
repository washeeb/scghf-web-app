/**
 * Cookie consent: the notice, the preferences, and the gate.
 *
 * ── The gate ────────────────────────────────────────────────────────────────
 *
 * Any script that sets a non-essential cookie is written as
 * `<script type="text/plain" data-consent="analytics">…</script>`. A browser
 * does not run text/plain, so until the category is allowed the script is
 * inert. `applyConsent()` clones each allowed script with a real type,
 * which is what makes it execute. Withdrawing a category reloads the page:
 * a script that has already run cannot be un-run, and pretending it can
 * is the dishonest kind of consent banner.
 *
 * ── The cookie ──────────────────────────────────────────────────────────────
 *
 * `scghf_consent` is JSON with a timestamp, kept six months, readable here
 * and by the server (it is excepted from cookie encryption). No cookie
 * means no decision yet, and the notice shows. "Essential only" is a
 * decision too, and is remembered the same way.
 */
const COOKIE = 'scghf_consent';
const SIX_MONTHS = 60 * 60 * 24 * 182;
const CATEGORIES = ['analytics', 'media'];

export function initCookieConsent() {
    const notice = document.querySelector('[data-cookie-consent]');
    const dialog = document.querySelector('[data-cookie-preferences]');

    const current = read();

    applyConsent(current);

    if (!notice) {
        return;
    }

    if (current === null) {
        notice.hidden = false;
    }

    notice.querySelector('[data-cookie-consent-all]')?.addEventListener('click', () => decide({ analytics: true, media: true }));
    notice.querySelector('[data-cookie-consent-essential]')?.addEventListener('click', () => decide({ analytics: false, media: false }));

    const open = () => {
        if (!dialog || typeof dialog.showModal !== 'function') {
            return;
        }

        const existing = read() || {};
        dialog.querySelectorAll('[data-cookie-category]').forEach((box) => {
            box.checked = existing[box.dataset.cookieCategory] === true;
        });
        dialog.showModal();
    };

    notice.querySelector('[data-cookie-consent-open]')?.addEventListener('click', open);
    document.querySelectorAll('[data-cookie-consent-manage]').forEach((el) => el.addEventListener('click', (event) => {
        event.preventDefault();
        open();
    }));

    dialog?.addEventListener('close', () => {
        if (dialog.returnValue !== 'save') {
            return;
        }

        const chosen = {};
        dialog.querySelectorAll('[data-cookie-category]').forEach((box) => {
            chosen[box.dataset.cookieCategory] = box.checked;
        });
        decide(chosen);
    });

    function decide(chosen) {
        const before = read();
        const value = { v: 1, at: Math.floor(Date.now() / 1000) };
        CATEGORIES.forEach((c) => { value[c] = chosen[c] === true; });

        write(value);
        notice.hidden = true;

        const withdrew = before !== null && CATEGORIES.some((c) => before[c] === true && value[c] !== true);

        if (withdrew) {
            window.location.reload();

            return;
        }

        applyConsent(value);
    }
}

/** Run every gated script whose category is allowed. Idempotent. */
export function applyConsent(consent) {
    if (!consent) {
        return;
    }

    document.querySelectorAll('script[type="text/plain"][data-consent]').forEach((script) => {
        if (consent[script.dataset.consent] !== true || script.dataset.consentApplied) {
            return;
        }

        const live = document.createElement('script');
        [...script.attributes].forEach((attr) => {
            if (attr.name !== 'type' && attr.name !== 'data-consent') {
                live.setAttribute(attr.name, attr.value);
            }
        });
        live.type = 'text/javascript';
        live.text = script.text;
        script.dataset.consentApplied = '1';
        script.after(live);
    });

    document.querySelectorAll('[data-consent-src]').forEach((el) => {
        if (consent[el.dataset.consent] === true && !el.getAttribute('src')) {
            el.setAttribute('src', el.dataset.consentSrc);
        }
    });
}

function read() {
    const match = document.cookie.split('; ').find((c) => c.startsWith(COOKIE + '='));

    if (!match) {
        return null;
    }

    try {
        const parsed = JSON.parse(decodeURIComponent(match.slice(COOKIE.length + 1)));

        return parsed && parsed.v === 1 ? parsed : null;
    } catch {
        return null;
    }
}

function write(value) {
    const secure = window.location.protocol === 'https:' ? ';Secure' : '';
    document.cookie = `${COOKIE}=${encodeURIComponent(JSON.stringify(value))};path=/;max-age=${SIX_MONTHS};SameSite=Lax${secure}`;
}
