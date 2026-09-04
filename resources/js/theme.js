/**
 * The theme control.
 *
 * The *initial* theme is applied by an inline script in the head — see
 * App\Support\ThemePreference — because anything loaded as a module runs after
 * the first paint, and correcting the theme after painting IS the flash.
 *
 * This file only handles what happens afterwards: the toggle, and following the
 * system when the visitor asked to.
 */

const KEY = 'scghf_theme';
const YEAR = 365 * 86400;

function stored() {
    try {
        const value = localStorage.getItem(KEY);
        if (value === 'light' || value === 'dark' || value === 'system') {
            return value;
        }
    } catch {
        // Safari private mode throws rather than returning null.
    }

    return 'system';
}

function systemPrefersDark() {
    return window.matchMedia?.('(prefers-color-scheme: dark)').matches ?? false;
}

function apply(preference) {
    const dark = preference === 'dark' || (preference === 'system' && systemPrefersDark());

    document.documentElement.classList.toggle('dark', dark);
    document.documentElement.dataset.theme = preference;

    /*
     * The mobile browser chrome. Without this a dark page keeps a white bar
     * above it on Android, which reads as a rendering bug rather than a theme.
     */
    document
        .querySelector('meta[name="theme-color"]')
        ?.setAttribute('content', dark ? '#0b0b0d' : '#ffffff');
}

function persist(preference) {
    try {
        localStorage.setItem(KEY, preference);
    } catch {
        // Falls back to the cookie, which is what the server reads anyway.
    }

    // The cookie is what lets the SERVER render the right theme on the next
    // request, so there is nothing to correct and nothing to flash.
    document.cookie = `${KEY}=${preference};path=/;max-age=${YEAR};SameSite=Lax`;
}

export function initTheme() {
    const preference = stored();

    document.querySelectorAll('[data-theme-toggle]').forEach((control) => {
        control.value = preference;

        control.addEventListener('change', (event) => {
            const next = event.target.value;
            apply(next);
            persist(next);
        });
    });

    /*
     * Follow the operating system, but only for somebody who chose to.
     *
     * This is the whole reason `system` is a distinct state rather than "no
     * preference": a visitor who picked dark should stay dark when their laptop
     * flips at sunset, and one who picked system should follow it.
     */
    window.matchMedia?.('(prefers-color-scheme: dark)').addEventListener('change', () => {
        if (stored() === 'system') {
            apply('system');
        }
    });
}
