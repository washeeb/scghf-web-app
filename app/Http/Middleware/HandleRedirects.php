<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Redirect;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Serving redirects, and recording the 404s that should become them.
 *
 * ── The table existed, the model was complete, and nothing called it ────────
 *
 * `redirects` has been in the schema since Phase 3. `Redirect::resolve()`
 * follows chains to their destination so a visitor never pays for two hops;
 * `Redirect::record404()` captures a miss with its referrer and refuses to
 * record scanner noise; `unresolved404s()` is the editor's work queue. All
 * three were written, tested and reachable from nowhere.
 *
 * A foundation could have entered fifty redirects before launch and every one
 * of them would have 404ed — which is the failure that looks least like a bug,
 * because the page was already missing.
 *
 * ── After the response, not before it ───────────────────────────────────────
 *
 * The lookup runs only once the application has decided the path is a 404.
 * Checking first would put a query in front of every request on the site — the
 * ones that resolve perfectly well included — against a table that is empty on
 * most days.
 *
 * ── The 404 log is the same table ───────────────────────────────────────────
 *
 * Not a separate one. A recorded 404 is a redirect with no destination yet:
 * `source = auto_404`, `to_path` null, `is_active` false. An editor fills in
 * the destination and it becomes a working redirect — the whole
 * "404-to-redirect workflow" the blueprint asks for, with no import step and no
 * second screen.
 *
 * ── It never turns a 404 into a 500 ─────────────────────────────────────────
 *
 * Everything here is wrapped. This runs on the error path, and a redirect table
 * that cannot be read must leave the visitor with the 404 they were already
 * getting rather than an error on top of it.
 */
class HandleRedirects
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // GET only. A POST that 404s is a broken form or a probe, and
        // redirecting it would turn a failed submission into a silent one.
        if ($response->getStatusCode() !== 404 || ! $request->isMethod('GET')) {
            return $response;
        }

        try {
            $redirect = Redirect::resolve($request->path());

            if ($redirect?->to_path !== null) {
                $redirect->recordHit($request->headers->get('referer'));

                return redirect()->to($this->destination($redirect, $request), $redirect->status_code);
            }

            Redirect::record404($request->path(), $request->headers->get('referer'));
        } catch (Throwable) {
            // See the note at the top.
        }

        return $response;
    }

    /**
     * Where to send them.
     *
     * The query string is carried across only when the redirect says so, and
     * `preserve_query` is off by default: dragging a stale `?utm_source` from a
     * two-year-old newsletter onto the new page attributes today's donation to
     * a campaign that ended, which is worse than losing the parameter.
     */
    private function destination(Redirect $redirect, Request $request): string
    {
        $query = $request->getQueryString();

        return ($redirect->preserve_query && $query !== null)
            ? $redirect->to_path.(str_contains($redirect->to_path, '?') ? '&' : '?').$query
            : $redirect->to_path;
    }
}
