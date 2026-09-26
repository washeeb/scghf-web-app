<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Empty the web workers' OPcache after a deploy.
 *
 * ── Why the deploy needs this ───────────────────────────────────────────────
 *
 * PHP-FPM keeps compiled bytecode in shared memory across requests and,
 * on shared hosting, across deploys — nothing restarts the pool when a
 * release is flipped. Every artisan command the deploy runs is a fresh
 * CLI process with a fresh cache and sees the new code; the web workers
 * can keep serving what they compiled before. The first staging deploy
 * of the launch content showed exactly that: pictures present in every
 * in-process render, absent from every real response, until
 * `opcache_reset()` ran inside a web request.
 *
 * `opcache_reset()` only reaches the pool it runs in, so the reset has to
 * be an HTTP request, and this is that request.
 *
 * ── Why a one-time token and not a secret ───────────────────────────────────
 *
 * `scghf:opcache-reset` mints a random token into the shared cache store
 * with a two-minute life and calls this route with it; the route pulls
 * the token (so it works once) and resets. There is no standing secret
 * to leak or rotate, nothing to add to `.env`, and an attacker who could
 * write to the cache store already has the database. The only effect of
 * calling it is a cache flush the next request refills — a nuisance at
 * worst, which is why the route is also rate-limited.
 */
class OpcacheResetController extends Controller
{
    public const PREFIX = 'opcache-reset:';

    public function __invoke(Request $request): JsonResponse
    {
        $token = (string) $request->input('token', '');

        if ($token === '' || strlen($token) > 128 || ! Cache::pull(self::PREFIX.$token)) {
            return response()->json(['reset' => false, 'reason' => 'no such token'], 403);
        }

        if (! function_exists('opcache_reset')) {
            return response()->json(['reset' => false, 'reason' => 'opcache not available in the web process']);
        }

        $status = function_exists('opcache_get_status') ? opcache_get_status(false) : false;
        $before = is_array($status) ? (int) ($status['opcache_statistics']['num_cached_scripts'] ?? 0) : null;

        // The realpath cache too: it is how a worker keeps resolving the
        // `current` symlink to the release it pointed at two minutes ago.
        clearstatcache(true);
        $ok = @opcache_reset();

        return response()->json([
            'reset' => (bool) $ok,
            'scripts_before' => $before,
            'sapi' => PHP_SAPI,
        ]);
    }
}
