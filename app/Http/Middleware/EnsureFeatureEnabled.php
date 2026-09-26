<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Features;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A feature flag that actually switches something off.
 *
 * ── The flags have been configuration with no consequence ──────────────────
 *
 * `FEATURE_SHOP=true` has sat in `.env.example` since Phase 2, and until this
 * middleware nothing on the request path read it: turning it off changed
 * nothing a visitor could see. A flag that cannot close a route is a comment.
 *
 * ── 404, not 403 ────────────────────────────────────────────────────────────
 *
 * A switched-off module should be indistinguishable from one that was never
 * built. A 403 says "there is something here you may not see", which invites
 * exactly the curiosity a foundation pausing its shop does not want — and a
 * 404 is what every link to it, the sitemap and the navigation already produce.
 *
 * Usage: `->middleware('feature:shop')`.
 */
class EnsureFeatureEnabled
{
    public function __construct(private readonly Features $features) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        if ($this->features->disabled($feature)) {
            throw new NotFoundHttpException;
        }

        return $next($request);
    }
}
