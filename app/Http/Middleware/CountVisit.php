<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\VisitorStat;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Counts a page view. Counts nothing about the person who caused it.
 *
 * ── What it deliberately never touches ──────────────────────────────────────
 *
 * The IP address. The full user agent. The query string. Any cookie other than
 * the session the application already sets for its own reasons.
 *
 * The query string is the one people forget: it carries unsubscribe tokens,
 * email addresses, search terms and receipt references, and a "path" that
 * included it would put all of them in an analytics table. So only the path is
 * recorded, and only after the ignore list.
 *
 * ── Sessions, not people ────────────────────────────────────────────────────
 *
 * A "session" here is one visit as the application already understands it,
 * marked once with a flag in that session. It is not a person: the same person
 * on a phone and a laptop is two, and one browser cleared is two more.
 * Deliberately so — the alternative is minting an identifier for people who did
 * not ask to be counted.
 *
 * ── It runs AFTER the response has been SENT ───────────────────────────────
 *
 * Counting is not worth a millisecond of a donor's time on a 3G connection, and
 * it is certainly not worth a 500. What to count is decided in `handle()`
 * (the session flag has to be written before the session is saved); the four
 * writes happen in `terminate()`, after PHP-FPM has flushed the response to
 * the visitor. Every failure is swallowed: a broken counter must never break a
 * page. Bound as a singleton so the pending rows survive to `terminate()`.
 */
class CountVisit
{
    private const SESSION_FLAG = 'visit_counted';

    /** @var array<int, array{string, string, bool}> dimension, value, new session */
    private array $pending = [];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        try {
            $this->pending = $this->rowsFor($request, $response);
        } catch (Throwable) {
            $this->pending = [];
        }

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        $rows = $this->pending;
        $this->pending = [];

        foreach ($rows as [$dimension, $value, $newSession]) {
            try {
                VisitorStat::increment_($dimension, $value, $newSession);
            } catch (Throwable) {
            }
        }
    }

    /** @return array<int, array{string, string, bool}> */
    private function rowsFor(Request $request, Response $response): array
    {
        if (! $this->shouldCount($request, $response)) {
            return [];
        }

        $newSession = ! $request->session()->has(self::SESSION_FLAG);

        if ($newSession) {
            $request->session()->put(self::SESSION_FLAG, true);
        }

        $rows = [[VisitorStat::DIMENSION_TOTAL, '', $newSession]];

        foreach (config('system.visitors.dimensions', []) as $dimension) {
            $value = $this->value($dimension, $request);

            if ($value === null) {
                continue;
            }

            if (VisitorStat::dimensionIsFull($dimension)) {
                $value = '(other)';
            }

            $rows[] = [$dimension, $value, $newSession];
        }

        return $rows;
    }

    private function shouldCount(Request $request, Response $response): bool
    {
        if (! config('system.visitors.enabled', true)) {
            return false;
        }

        // Only real page views by real browsers. An asset, an API call or a
        // redirect is not a visit, and a 404 is not a page.
        if (! $request->isMethod('GET') || ! $response->isSuccessful() || $request->ajax()) {
            return false;
        }

        if (! $request->hasSession()) {
            return false;
        }

        /** @var array<int, string> $ignored */
        $ignored = config('system.visitors.ignore_paths', []);

        if ($request->is(...$ignored)) {
            return false;
        }

        return ! (config('system.visitors.ignore_bots', true) && $this->looksLikeABot($request));
    }

    private function value(string $dimension, Request $request): ?string
    {
        return match ($dimension) {
            // The path only. No query string — that is where tokens, email
            // addresses and search terms live.
            VisitorStat::DIMENSION_PATH => '/'.ltrim($request->path(), '/'),

            // The referring HOST, never the full URL. Knowing traffic came from
            // Facebook is useful; knowing which private group post it came from
            // is somebody else's business.
            VisitorStat::DIMENSION_REFERRER => $this->referrerHost($request),

            VisitorStat::DIMENSION_DEVICE => $this->deviceType($request),

            default => null,
        };
    }

    private function referrerHost(Request $request): ?string
    {
        $referrer = $request->headers->get('referer');

        if ($referrer === null) {
            return 'direct';
        }

        $host = parse_url($referrer, PHP_URL_HOST);

        if (! is_string($host)) {
            return 'direct';
        }

        return $host === $request->getHost() ? 'internal' : Str::lower($host);
    }

    /**
     * mobile, tablet or desktop — the coarse class the request already
     * announced.
     *
     * Not a fingerprint: three possible values, derived from nothing the
     * request did not already say out loud. It earns its place because this
     * foundation's readers are disproportionately on low-end Android handsets,
     * and that fact is what justifies the performance budget.
     */
    private function deviceType(Request $request): string
    {
        $agent = Str::lower((string) $request->userAgent());

        return match (true) {
            str_contains($agent, 'ipad'), str_contains($agent, 'tablet') => 'tablet',
            str_contains($agent, 'mobi'), str_contains($agent, 'android') => 'mobile',
            default => 'desktop',
        };
    }

    /**
     * Bots inflate every number they touch.
     *
     * Matched on the user agent alone — nothing more than what the request
     * already announced about itself, and nothing that identifies a person.
     * Crude, and crude is fine: a bot that lies about being a browser is
     * counted, which overstates the audience slightly rather than recording
     * anything it should not.
     */
    private function looksLikeABot(Request $request): bool
    {
        $agent = Str::lower((string) $request->userAgent());

        if ($agent === '') {
            return true;
        }

        foreach (['bot', 'crawl', 'spider', 'slurp', 'curl', 'wget', 'python-requests', 'headless'] as $needle) {
            if (str_contains($agent, $needle)) {
                return true;
            }
        }

        return false;
    }
}
