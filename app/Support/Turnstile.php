<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cloudflare Turnstile — the third line behind the honeypot and the throttle.
 *
 * ── Only on the form that is a target ───────────────────────────────────────
 *
 * A donation form is where stolen card numbers get tested, because a small
 * gift to a charity is the charge least likely to be queried. The honeypot
 * stops the naive bots and the throttle bounds the rest; Turnstile stops the
 * ones that read the honeypot's field name and pace themselves under the
 * limit. It is not on the contact form or the newsletter: a challenge on a
 * form nobody attacks is a wall in front of the people least able to climb
 * it.
 *
 * ── Fails closed on "no", fails open on silence ─────────────────────────────
 *
 * A token Cloudflare rejects is refused. A verification we could not
 * complete — their endpoint down, the shared host's outbound connection
 * timing out — lets the gift through with a warning in the log, because an
 * outage in Cloudflare's network must not stop a market trader in Tamale
 * giving twenty cedis. The throttle still applies to those requests.
 *
 * ── Off until both keys exist ───────────────────────────────────────────────
 *
 * With either key blank the widget is not drawn and the token is not
 * required, so a local checkout and CI need no Cloudflare account and the
 * form works exactly as before. `.env.example` documents the test keys.
 */
final class Turnstile
{
    public const FIELD = 'cf-turnstile-response';

    public static function enabled(): bool
    {
        return filled(config('services.turnstile.site_key')) && filled(config('services.turnstile.secret_key'));
    }

    public static function siteKey(): string
    {
        return (string) config('services.turnstile.site_key');
    }

    /** Whether the token the browser sent stands for a person, as far as Cloudflare can tell. */
    public static function verify(?string $token, ?string $ip = null): bool
    {
        if (! self::enabled()) {
            return true;
        }

        if (blank($token)) {
            return false;
        }

        try {
            $response = Http::asForm()
                ->timeout(5)
                ->post((string) config('services.turnstile.verify_url'), array_filter([
                    'secret' => config('services.turnstile.secret_key'),
                    'response' => $token,
                    'remoteip' => $ip,
                ]));
        } catch (ConnectionException $e) {
            Log::warning('Turnstile could not be reached; the submission was allowed through.', ['error' => $e->getMessage()]);

            return true;
        }

        if ($response->serverError()) {
            Log::warning('Turnstile answered with a server error; the submission was allowed through.', ['status' => $response->status()]);

            return true;
        }

        return $response->ok() && $response->json('success') === true;
    }
}
