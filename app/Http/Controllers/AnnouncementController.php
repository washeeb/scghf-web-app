<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Announcement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Clicks on, and dismissals of, a notice.
 *
 * ── Why the click goes through here at all ──────────────────────────────────
 *
 * `announcements.clicks` has been on the table since Phase 3 and incremented by
 * nothing. Without it the foundation cannot answer the only question that
 * matters about a bar across every page: is anybody acting on it, or is it a
 * strip of a phone screen spent for nothing?
 *
 * The cost is one redirect on a link somebody chose to follow, which is the
 * cheapest place to put it.
 */
class AnnouncementController extends Controller
{
    /**
     * Count the click, then send them where the notice pointed.
     *
     * ── The destination is validated, not trusted ──────────────────────────
     *
     * `cta_url` is typed by an editor, and an open redirect is an open redirect
     * whoever typed it. An external destination is allowed — a notice may well
     * point at a partner's appeal — but it must be a real absolute URL rather
     * than a `javascript:` scheme or a relative string that would resolve
     * against this host in a way the editor did not intend.
     */
    public function click(Announcement $announcement): RedirectResponse
    {
        abort_unless($announcement->isLive(), 404);

        $url = (string) $announcement->cta_url;

        abort_if(blank($url), 404);

        if (! preg_match('#^(https?://|/)#i', $url)) {
            abort(404);
        }

        $announcement->recordClick();

        return redirect()->away($url);
    }

    /**
     * Remember that this visitor closed it.
     *
     * A cookie rather than a database row, because the visitor is anonymous and
     * creating a record for every person who closes a banner would be both a
     * pointless table and, under Act 843, personal data collected for no
     * purpose the foundation could justify.
     *
     * Same-site and http-only: nothing reads it but the server, and it is not
     * worth sending on a cross-site request.
     */
    public function dismiss(Request $request, Announcement $announcement): Response
    {
        $days = max(1, min(365, (int) $announcement->dismiss_days));

        return response()->noContent()->withCookie(new Cookie(
            name: $announcement->dismissalCookieName(),
            value: '1',
            expire: now()->addDays($days)->getTimestamp(),
            path: '/',
            secure: $request->isSecure(),
            httpOnly: true,
            sameSite: Cookie::SAMESITE_LAX,
        ));
    }
}
