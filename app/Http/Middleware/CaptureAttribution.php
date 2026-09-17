<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Attribution;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** First-touch campaign parameters into the session. See Attribution. */
class CaptureAttribution
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('GET')) {
            Attribution::capture($request);
        }

        return $next($request);
    }
}
