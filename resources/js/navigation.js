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

/** A pointer that can rest on something: a mouse or trackpad, never a finger. */
const CAN_HOVER = '(hover: hover) and (pointer: fine)';

/** How long the pointer may leave a dropdown before it closes. Long enough to cross the gap. */
const HOVER_LEAVE_MS = 220;

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

    initHover();

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

/*
 * Open on hover — for a mouse, when the site asks for it.
 *
 * Everything the disclosure already does stays: click and Enter open it,
 * Escape and click-away close it, a finger gets exactly the tap-to-open it
 * had. This only adds that, with a pointer that can hover, resting on the
 * summary opens the menu and leaving the whole dropdown closes it after a
 * short grace period — so crossing the gap between the summary and the list
 * does not slam it shut.
 *
 * The switch is Settings → Site → "Open menus on hover", read from the nav
 * element's data attribute so an editor can turn it off without a deploy.
 */
function initHover() {
    const nav = document.querySelector('[data-nav-hover]');

    if (!nav || nav.dataset.navHover !== '1' || !window.matchMedia?.(CAN_HOVER).matches) {
        return;
    }

    nav.querySelectorAll('[data-nav-dropdown]').forEach((details) => {
        let leaveTimer = null;

        const cancelClose = () => {
            if (leaveTimer !== null) {
                clearTimeout(leaveTimer);
                leaveTimer = null;
            }
        };

        details.addEventListener('pointerenter', (event) => {
            if (event.pointerType !== 'mouse') {
                return;
            }

            cancelClose();

            if (!details.open) {
                details.open = true;
            }
        });

        details.addEventListener('pointerleave', (event) => {
            if (event.pointerType !== 'mouse') {
                return;
            }

            cancelClose();
            leaveTimer = setTimeout(() => {
                // Never close under a keyboard user who tabbed in from the mouse-opened menu.
                if (!details.contains(document.activeElement) || document.activeElement === details.querySelector('summary')) {
                    close(details);
                }
            }, HOVER_LEAVE_MS);
        });
    });
}
