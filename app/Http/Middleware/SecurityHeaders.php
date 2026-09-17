<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * The response headers every page leaves with.
 *
 * ── A nonce per request ─────────────────────────────────────────────────────
 *
 * The CSP forbids inline script unless it carries this request's nonce.
 * `Vite::useCspNonce()` puts it on the built asset tags; `$cspNonce` is
 * shared with every view for the two inline scripts the layout has (the
 * theme, before first paint; the popup config). Anything else inline is a
 * bug the browser now reports rather than runs.
 *
 * ── The admin panel is the exception, on purpose ────────────────────────────
 *
 * Filament injects its own inline scripts and styles and Alpine needs
 * `unsafe-eval`. Enforcing the strict policy there would break every
 * screen; sending it report-only means a change that would break it is
 * seen first. `config('security.headers')` explains the rest.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Str::random(32);

        Vite::useCspNonce($nonce);
        view()->share('cspNonce', $nonce);

        /** @var Response $response */
        $response = $next($request);

        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff', false);
        $headers->set('X-Frame-Options', 'SAMEORIGIN', false);
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin', false);
        $headers->set('Permissions-Policy', (string) config('security.headers.permissions_policy', ''), false);
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin', false);
        $headers->remove('X-Powered-By');

        $hsts = (int) config('security.headers.hsts_max_age', 0);

        if ($hsts > 0 && $request->isSecure()) {
            $headers->set('Strict-Transport-Security', "max-age={$hsts}; includeSubDomains", false);
        }

        if ($this->isAdminPanel($request)) {
            $headers->set('Content-Security-Policy-Report-Only', $this->policy($nonce, strict: false), false);
        } else {
            $headers->set('Content-Security-Policy', $this->policy($nonce, strict: true), false);
        }

        return $response;
    }

    /** The policy as a string. The strict form is the one the public site enforces. */
    public static function policy(string $nonce, bool $strict): string
    {
        $csp = (array) config('security.headers.csp', []);
        $list = fn (string $key): string => implode(' ', (array) ($csp[$key] ?? []));

        $script = $strict
            ? "'self' 'nonce-{$nonce}' ".$list('script_origins')
            : "'self' 'nonce-{$nonce}' 'unsafe-inline' 'unsafe-eval' ".$list('script_origins');

        // Inline `style=""` attributes carry theme colours computed per record
        // (a division's colour, a progress bar's width). A nonce cannot be put
        // on an attribute, so attributes are allowed and <style> elements are
        // nonced — the split CSP3 exists for.
        $style = $strict
            ? "'self' 'nonce-{$nonce}'"
            : "'self' 'nonce-{$nonce}' 'unsafe-inline'";

        $directives = [
            "default-src 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "frame-ancestors 'self'",
            "form-action 'self' ".$list('form_action_origins'),
            "script-src {$script}",
            "style-src {$style}",
            "style-src-attr 'unsafe-inline'",
            "img-src 'self' ".$list('img_origins'),
            "font-src 'self' ".$list('font_origins'),
            "connect-src 'self' ".$list('connect_origins'),
            "frame-src 'self' ".$list('frame_origins'),
            'report-uri '.route('csp.report'),
        ];

        if (app()->isProduction()) {
            $directives[] = 'upgrade-insecure-requests';
        }

        return implode('; ', array_map('trim', $directives));
    }

    private function isAdminPanel(Request $request): bool
    {
        $path = trim((string) config('admin.path', 'scghf-office'), '/');

        return $request->is($path) || $request->is($path.'/*') || $request->is('livewire/*') || $request->is('filament/*');
    }
}
