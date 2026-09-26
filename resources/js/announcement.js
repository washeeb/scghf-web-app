/**
 * Closing the announcement bar.
 *
 * ── The button is created here, not in the template ─────────────────────────
 *
 * If Blade rendered it, a visitor with JavaScript blocked or still loading
 * would see a close button that does nothing when pressed. A control that
 * ignores you is worse than one that was never offered — so the button exists
 * only where the code that makes it work exists.
 *
 * ── The server is told, not just the DOM ────────────────────────────────────
 *
 * Hiding the element would bring the bar straight back on the next page. The
 * POST sets an http-only cookie for `dismiss_days`, and `Announcement::
 * forRequest()` checks it before the bar is rendered at all — so a dismissed
 * notice is never drawn again and, just as importantly, is never counted as
 * seen again.
 *
 * ── It hides the bar first and asks afterwards ──────────────────────────────
 *
 * On a slow connection the round trip can take a second or more, and a close
 * button that leaves the thing on screen while it thinks reads as broken. The
 * worst case if the request fails is that the bar returns on the next page,
 * which is exactly what would have happened anyway.
 */
export function initAnnouncement() {
    const bar = document.querySelector('[data-announcement][data-announcement-dismissible]');

    if (!bar) {
        return;
    }

    const button = document.createElement('button');

    button.type = 'button';
    button.className =
        'ml-2 shrink-0 rounded px-2 text-lg leading-none opacity-70 hover:opacity-100 ' +
        'focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 ' +
        'focus-visible:outline-[var(--focus-ring)]';

    // A screen reader needs to know what the × does; a sighted user does not
    // need the word. `aria-label` on the button, and the glyph hidden from the
    // accessibility tree so it is not read out as "multiplication sign".
    button.setAttribute('aria-label', bar.dataset.announcementCloseLabel || 'Close this announcement');
    button.innerHTML = '<span aria-hidden="true">&times;</span>';

    button.addEventListener('click', () => {
        bar.remove();

        const token = document.querySelector('meta[name="csrf-token"]')?.content;

        fetch(`/announcements/${bar.dataset.announcement}/dismiss`, {
            method: 'POST',
            headers: token ? { 'X-CSRF-TOKEN': token } : {},
            credentials: 'same-origin',
        }).catch(() => {
            // Deliberately silent. The bar is already gone for this page, and
            // the only consequence of a failure is that it comes back on the
            // next one — which is not worth an error message to a visitor who
            // just wanted it out of the way.
        });
    });

    bar.querySelector('[data-announcement-actions]')?.append(button);
}
