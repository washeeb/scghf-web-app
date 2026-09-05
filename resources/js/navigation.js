/**
 * The navigation, once JavaScript has arrived.
 *
 * ── Everything here is a convenience, and that is deliberate ────────────────
 *
 * The menus are `<details>` elements. They open, close, announce their state
 * and respond to Enter with no script at all, because the browser already
 * implements the disclosure pattern. This file adds Escape, click-away, and
 * tidying up on resize.
 *
 * If it never loads — a data-saver proxy that rewrites scripts, a phone that
 * gave up on the bundle, a parse error in something else — the navigation still
 * works. Nothing below is load-bearing, and nothing below should become so.
 * That is the test to apply to anything added here later.
 */

const DESKTOP = '(min-width: 768px)'; // Tailwind's `md`, where the panel is replaced by the row.

/** Every disclosure this file manages. */
function menus() {
    return document.querySelectorAll('[data-mobile-nav], [data-nav-dropdown]');
}

function close(details) {
    details.open = false;
}

/**
 * Close everything except one, so two dropdowns are never open at once.
 *
 * Left open, a second dropdown overlaps the first and the visitor is reading
 * two lists stacked on top of each other. The browser has no opinion about
 * sibling `<details>` unless they share a `name`, and `name` groups them so
 * tightly that it also closes the mobile panel when a section inside it opens —
 * which is the wrong behaviour for a nested accordion.
 */
function closeOthers(except) {
    menus().forEach((details) => {
        if (details !== except && !details.contains(except) && details.open) {
            close(details);
        }
    });
}

export function initNavigation() {
    menus().forEach((details) => {
        details.addEventListener('toggle', () => {
            if (details.open) {
                closeOthers(details);
            }
        });
    });

    /*
     * Escape closes, and gives focus back to the control that opened it.
     *
     * Without the second half the visitor is returned to the top of the
     * document, which for a keyboard user means tabbing all the way back to
     * where they were — the exact thing the skip link exists to avoid.
     */
    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') {
            return;
        }

        const open = document.querySelector('[data-mobile-nav][open], [data-nav-dropdown][open]');

        if (!open) {
            return;
        }

        close(open);
        open.querySelector('summary')?.focus();
    });

    /*
     * A click anywhere else closes an open menu.
     *
     * `pointerdown` rather than `click`, so the menu is already gone by the
     * time the thing underneath receives its own event — closing on `click`
     * means the first tap outside is spent dismissing the menu and the second
     * one does what the visitor wanted.
     */
    document.addEventListener('pointerdown', (event) => {
        menus().forEach((details) => {
            if (details.open && !details.contains(event.target)) {
                close(details);
            }
        });
    });

    /*
     * A focus that lands outside an open menu closes it too.
     *
     * Tabbing past the last link in a dropdown moves focus to whatever follows
     * it in the document while leaving the dropdown hanging open over the page.
     * Pointer events never notice, because nobody clicked anything.
     */
    document.addEventListener('focusin', (event) => {
        menus().forEach((details) => {
            if (details.open && !details.contains(event.target)) {
                close(details);
            }
        });
    });

    /*
     * Crossing the breakpoint closes the mobile panel.
     *
     * A panel left open while the layout switches to the desktop row is a
     * `<details open>` that is now `display: none` — invisible, still open, and
     * still the first thing keyboard focus finds when the window narrows again.
     */
    window.matchMedia?.(DESKTOP).addEventListener('change', (event) => {
        if (event.matches) {
            document.querySelectorAll('[data-mobile-nav][open]').forEach(close);
        } else {
            document.querySelectorAll('[data-nav-dropdown][open]').forEach(close);
        }
    });
}
