<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends an idle admin session, and keeps a live one alive.
 *
 * `ADMIN_SESSION_TIMEOUT` has been documented in .env.example since Phase 2
 * with nothing reading it. This reads it.
 *
 * ── Why the admin timeout is separate from the session lifetime ─────────────
 *
 * `SESSION_LIFETIME` governs everybody, donors included. A donor's session
 * living for two hours on their own phone is convenient and costs little.
 *
 * An admin panel is a different exposure: it is frequently open on a shared
 * office machine, and the account behind it can read beneficiary case files and
 * export donor records. So staff get their own, shorter clock, measured from
 * their last request rather than from sign-in — an administrator working
 * steadily is never interrupted, and one who walked away an hour ago is signed
 * out.
 */
class RecordAdminActivity
{
    private const KEY = 'admin_last_active_at';

    private const SIGNED_IN_AT = 'admin_signed_in_at';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $timeout = (int) config('admin.session_timeout', 60);

        if (! $user instanceof User || $timeout < 1 || ! $request->hasSession()) {
            return $next($request);
        }

        $lastActive = $request->session()->get(self::KEY);
        $signedInAt = $request->session()->get(self::SIGNED_IN_AT);
        $absolute = (int) config('admin.absolute_timeout', 720);

        if (! is_int($signedInAt)) {
            $request->session()->put(self::SIGNED_IN_AT, $signedInAt = now()->timestamp);
        }

        $idleExpired = is_int($lastActive) && (now()->timestamp - $lastActive) > ($timeout * 60);
        $absoluteExpired = $absolute > 0 && (now()->timestamp - $signedInAt) > ($absolute * 60);

        if ($idleExpired || $absoluteExpired) {
            /*
             * Logged out and the session invalidated, not merely redirected.
             *
             * Leaving the session alive and bouncing the request would mean the
             * cookie on that shared machine still authenticates — which is the
             * exact thing the timeout exists to stop.
             */
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->guest(
                filament()->getCurrentOrDefaultPanel()?->getLoginUrl() ?? '/'
            );
        }

        $request->session()->put(self::KEY, now()->timestamp);

        return $next($request);
    }
}
