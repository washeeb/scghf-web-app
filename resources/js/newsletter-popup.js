/**
 * The newsletter popup: when it shows, and when it does not.
 *
 * ── Exit intent, or patience ────────────────────────────────────────────────
 *
 * With a fine pointer (a mouse), it shows the first time the cursor leaves
 * through the top of the viewport — the motion of reaching for the tabs or
 * the address bar. A phone has no cursor, so there it waits `delaySeconds`
 * AND for the page to have been scrolled halfway: somebody who has read half
 * the page has shown more interest than somebody who has been staring at
 * the hero for twenty-five seconds.
 *
 * ── Once per `frequencyDays`, ever per visit ────────────────────────────────
 *
 * Closing it, for any reason, writes the time to localStorage. Subscribing
 * sets a cookie server-side, and the dialog is not rendered at all after
 * that. Both are per-device, which is the honest scope of a promise like
 * "we will not show this again".
 *
 * ── Enhancement only ────────────────────────────────────────────────────────
 *
 * With this file blocked the <dialog> is simply never opened. Nothing else
 * on the page depends on it.
 */
const STORAGE_KEY = 'scghf.newsletter-popup.closed-at';

export function initNewsletterPopup() {
    const dialog = document.querySelector('[data-newsletter-popup]');

    if (!dialog || typeof dialog.showModal !== 'function') {
        return;
    }

    const frequencyDays = Number(dialog.dataset.frequencyDays || 30);
    const delaySeconds = Number(dialog.dataset.delaySeconds || 25);

    if (closedRecently(frequencyDays)) {
        return;
    }

    let shown = false;

    const show = () => {
        if (shown || dialog.open) {
            return;
        }

        shown = true;
        dialog.showModal();
        dialog.querySelector('input[type="email"]')?.focus();
    };

    const close = () => {
        remember();
        dialog.close();
    };

    dialog.querySelectorAll('[data-newsletter-popup-close]').forEach((button) => button.addEventListener('click', close));
    dialog.addEventListener('cancel', remember); // Escape
    dialog.addEventListener('submit', remember);

    if (window.matchMedia('(pointer: fine)').matches) {
        document.addEventListener('mouseout', (event) => {
            if (event.relatedTarget === null && event.clientY <= 0) {
                show();
            }
        });

        return;
    }

    let scrolledHalfway = false;
    let waited = false;
    const maybe = () => {
        if (scrolledHalfway && waited) {
            show();
        }
    };

    window.setTimeout(() => {
        waited = true;
        maybe();
    }, delaySeconds * 1000);

    window.addEventListener('scroll', () => {
        const height = document.documentElement.scrollHeight - window.innerHeight;

        if (height <= 0 || window.scrollY / height >= 0.5) {
            scrolledHalfway = true;
            maybe();
        }
    }, { passive: true });
}

function closedRecently(days) {
    try {
        const at = Number(window.localStorage.getItem(STORAGE_KEY) || 0);

        return at > 0 && Date.now() - at < days * 86400000;
    } catch {
        return false;
    }
}

function remember() {
    try {
        window.localStorage.setItem(STORAGE_KEY, String(Date.now()));
    } catch {
        // Private mode, or storage disabled. It may show again; nothing breaks.
    }
}
