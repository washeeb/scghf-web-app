<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * The admin panel from listed addresses only, when a list is set.
 *
 * An empty list is "anywhere", which is the default and the right one for
 * staff on mobile data. A refused request is a 404, not a 403: to a scanner
 * the panel then does not exist at that path, which is the point of the
 * non-obvious path in the first place. The refusal is logged with the
 * address so a locked-out colleague can be let in.
 */
class RestrictAdminByIp
{
    public function handle(Request $request, Closure $next): Response
    {
        $allowed = (array) config('admin.ip_allowlist', []);

        if ($allowed === []) {
            return $next($request);
        }

        $ip = (string) $request->ip();

        if (IpUtils::checkIp($ip, $allowed)) {
            return $next($request);
        }

        Log::warning('Admin panel request refused by the IP allowlist.', ['ip' => $ip, 'path' => $request->path()]);

        abort(404);
    }
}
