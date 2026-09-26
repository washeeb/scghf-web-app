<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * `X-Robots-Tag`, when this installation is not to be indexed.
 *
 * ── The meta tag alone was never enough ─────────────────────────────────────
 *
 * `.env.example` has said since Phase 2 that the indexing switch drives
 * "robots.txt AND the X-Robots-Tag header". Neither existed. The layout emitted
 * a `<meta name="robots">`, which is read only by a crawler that parses HTML —
 * and a crawler fetching an uploaded PDF, an annual report, a document served
 * through the download route or an image never parses any.
 *
 * On a staging deployment that is the difference between "not indexed" and "the
 * HTML is not indexed". A header covers every response the application sends.
 *
 * ── It never blocks a response ──────────────────────────────────────────────
 *
 * The setting comes from the database, and this middleware runs on every
 * request including the ones served while the database is unreachable. A
 * failure leaves the header off, which is the same position as before this
 * existed — an outage must not be made worse by the thing checking for one.
 */
class SetRobotsHeader
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        try {
            if (setting('seo.allow_indexing', false)) {
                return $response;
            }
        } catch (Throwable) {
            return $response;
        }

        /*
         * `noimageindex` as well, because an image indexed from a foundation's
         * staging site is a photograph of a beneficiary in an image search
         * result — and removing one from there is far harder than never
         * having it appear.
         */
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noimageindex', false);

        return $response;
    }
}
