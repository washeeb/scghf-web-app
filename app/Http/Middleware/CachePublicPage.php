<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\SiteCache;
use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response as BaseResponse;
use Throwable;

/**
 * Full-page cache for anonymous visitors.
 *
 * On shared hosting the expensive part of a request is PHP booting the
 * framework and running twenty queries to draw a page that has not changed
 * since the last visitor. For somebody who is not signed in, has nothing in
 * a basket, and is looking at a public page, the answer is the same bytes
 * every time — so they are stored once and served from a file.
 *
 * ── What keeps it correct ───────────────────────────────────────────────────
 *
 * - Only GET/HEAD, only a 200 HTML answer, only with no query string other
 *   than `page`, only when nobody is signed in and the session carries no
 *   flash (an error, a status message, a conversion event), and never on
 *   the paths in config('performance.page_cache.except').
 * - The key includes the site cache generation: any content save starts a
 *   new generation and every stored page is unreachable at once.
 * - The key varies on every `scghf_*` cookie: the theme, the cookie
 *   consent, dismissed announcements — the server renders differently for
 *   each, so each gets its own copy.
 * - The per-request CSP nonce and the CSRF token are replaced in the body
 *   on every hit. Both are random strings that appear nowhere else, so a
 *   string replace is exact. Without this a cached page would either fail
 *   the nonce policy or fail every form with a 419.
 * - A response that sets a cookie of its own (the basket, a dismissal) is
 *   not stored: replaying somebody else's Set-Cookie is not a page cache.
 *
 * `X-Page-Cache: hit|miss|skip` on every response, so a person can see it
 * work from the browser's network panel.
 */
class CachePublicPage
{
    private const PREFIX = 'page:';

    public function handle(Request $request, Closure $next): BaseResponse
    {
        if (! $this->cacheable($request)) {
            return $this->mark($next($request), 'skip');
        }

        $key = $this->key($request);

        try {
            $stored = $this->store()->get($key);
        } catch (Throwable) {
            $stored = null;
        }

        if (is_array($stored)) {
            return $this->mark($this->replay($stored), 'hit');
        }

        $response = $next($request);

        if ($this->storable($request, $response)) {
            try {
                $this->store()->put($key, [
                    'body' => (string) $response->getContent(),
                    'nonce' => $this->nonce(),
                    'csrf' => $request->session()->token(),
                    'content_type' => (string) $response->headers->get('Content-Type', 'text/html; charset=UTF-8'),
                ], (int) config('performance.page_cache.ttl', 600));
            } catch (Throwable) {
                // A full disk or an unwritable cache directory is not a reason
                // to fail a page that has already been rendered.
            }
        }

        return $this->mark($response, 'miss');
    }

    private function cacheable(Request $request): bool
    {
        if (! (bool) config('performance.page_cache.enabled', true)) {
            return false;
        }

        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return false;
        }

        if (array_diff(array_keys($request->query()), ['page']) !== []) {
            return false;
        }

        if ($request->user() !== null) {
            return false;
        }

        if (! $request->hasSession()) {
            return false;
        }

        $session = $request->session();

        // Flash data is a message for THIS visitor: an error under a field, a
        // "thank you" status, a conversion event for the analytics script.
        if ($session->get('_flash.old', []) !== [] || $session->get('_flash.new', []) !== []) {
            return false;
        }

        $except = (array) config('performance.page_cache.except', []);
        $admin = trim((string) config('admin.path', 'admin'), '/');
        $except[] = $admin;
        $except[] = $admin.'/*';
        $except[] = 'livewire/*';

        return ! $request->is(...$except);
    }

    private function storable(Request $request, BaseResponse $response): bool
    {
        if ($response->getStatusCode() !== 200) {
            return false;
        }

        if (! str_starts_with((string) $response->headers->get('Content-Type', ''), 'text/html')) {
            return false;
        }

        // A page that set a cookie is talking to this visitor specifically.
        // The session and CSRF cookies are the framework's own and expected.
        foreach ($response->headers->getCookies() as $cookie) {
            if (! in_array($cookie->getName(), [config('session.cookie'), 'XSRF-TOKEN'], true)) {
                return false;
            }
        }

        return $request->user() === null;
    }

    private function replay(array $stored): Response
    {
        $body = (string) ($stored['body'] ?? '');

        if (($stored['nonce'] ?? '') !== '') {
            $body = str_replace((string) $stored['nonce'], $this->nonce(), $body);
        }

        if (($stored['csrf'] ?? '') !== '' && request()->hasSession()) {
            $body = str_replace((string) $stored['csrf'], request()->session()->token(), $body);
        }

        return new Response($body, 200, ['Content-Type' => $stored['content_type'] ?? 'text/html; charset=UTF-8']);
    }

    private function key(Request $request): string
    {
        $vary = [];

        foreach ($request->cookies->all() as $name => $value) {
            if (str_starts_with((string) $name, 'scghf_')) {
                $vary[$name] = is_scalar($value) ? (string) $value : '';
            }
        }

        ksort($vary);

        return self::PREFIX.SiteCache::generation().':'.sha1(
            $request->getHost().'|'.$request->path().'|'.($request->query('page') ?? '').'|'.http_build_query($vary)
        );
    }

    private function nonce(): string
    {
        return (string) (Vite::cspNonce() ?? '');
    }

    private function store(): Repository
    {
        return Cache::store((string) config('performance.page_cache.store', 'pages'));
    }

    private function mark(BaseResponse $response, string $state): BaseResponse
    {
        $response->headers->set('X-Page-Cache', $state);

        return $response;
    }
}
