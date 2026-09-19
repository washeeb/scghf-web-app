<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Communications\DeliveryEventProcessor;
use App\Jobs\ProcessDeliveryWebhook;
use App\Models\InboundWebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Where bounces, complaints and delivery reports arrive.
 *
 * Module 7 built a suppression list and made the whole channel depend on
 * bounces reaching it. This is the thing that receives them; without it the
 * list stays empty, the bounce rate climbs, and the first thing to stop being
 * delivered is donation receipts.
 *
 * Same shape as the Paystack endpoint, for the same reasons:
 *
 *   1. read the RAW body
 *   2. store it, before parsing, with the signature verdict
 *   3. queue the work
 *   4. answer 200
 *
 * **Always 200, even on a bad signature.** Providers retry anything that is not
 * a 2xx, so answering 401 to a forged request buys nothing and turns a probe
 * into a retry storm. The rejection is recorded; the response is bland.
 *
 * An unauthenticated event is stored and never acted on. If an unsigned request
 * could produce a bounce, anybody who could guess a donor's email address could
 * stop their receipts arriving — a silent denial of service dressed as a
 * delivery report.
 */
class DeliveryWebhookController extends Controller
{
    /**
     * Meta's one-time subscription handshake (Wave 2): a GET with
     * `hub.mode=subscribe`, our verify token and a challenge to echo. Only
     * the token in .env answers it; anything else is a 403.
     */
    public function subscribe(Request $request, string $provider): Response
    {
        $expected = (string) config('communications.whatsapp.verify_token', '');

        if ($provider !== 'meta' || $expected === '' || $request->query('hub_mode') !== 'subscribe'
            || ! hash_equals($expected, (string) $request->query('hub_verify_token', ''))) {
            return response('', 403);
        }

        return response((string) $request->query('hub_challenge', ''), 200, ['Content-Type' => 'text/plain']);
    }

    public function __invoke(Request $request, string $provider, DeliveryEventProcessor $processor): Response
    {
        $known = (array) config('communications.webhooks.providers', []);

        if (! array_key_exists($provider, $known)) {
            // Not a provider this application talks to. 404 rather than 200:
            // there is nothing to retry, and no reason to look like a valid
            // endpoint to somebody enumerating paths.
            return response('', 404);
        }

        $rawBody = $request->getContent();
        $signature = $this->headerValue($request, (string) ($known[$provider]['signature_header'] ?? ''));

        // Meta sends `sha256=<hex>`; the comparison wants the hex.
        $prefix = (string) ($known[$provider]['prefix'] ?? '');

        if ($prefix !== '' && $signature !== null && str_starts_with($signature, $prefix)) {
            $signature = substr($signature, strlen($prefix));
        }

        try {
            $event = $processor->record(
                provider: $provider,
                channel: (string) ($known[$provider]['channel'] ?? InboundWebhookEvent::CHANNEL_EMAIL),
                rawBody: $rawBody,
                signatureValid: $this->verify($provider, $known[$provider], $rawBody, $signature, $request),
                signature: $signature,
                sourceIp: $request->ip(),
            );
        } catch (Throwable $e) {
            /*
             * Storing failed — a database problem, almost certainly. The one
             * case where a non-200 is right: the provider should retry, because
             * the event has NOT been recorded and would otherwise be lost.
             */
            Log::critical('Could not record an incoming delivery webhook.', [
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);

            return response('', 500);
        }

        if ($event->signature_valid && ! $event->isProcessed()) {
            ProcessDeliveryWebhook::dispatch($event->id);
        }

        return response('', 200);
    }

    /**
     * Whether the request is genuinely from the provider.
     *
     * ⚠ A provider with NO configured secret verifies as false, not true.
     *
     * The tempting shortcut is "no secret configured, so skip the check" — and
     * that turns an unconfigured endpoint into an open one that anybody can use
     * to suppress any address. Failing closed means an unconfigured provider
     * records events and acts on none of them, which is visible and harmless.
     *
     * @param  array<string, mixed>  $config
     */
    private function verify(
        string $provider,
        array $config,
        string $rawBody,
        ?string $signature,
        Request $request,
    ): bool {
        $secret = (string) ($config['secret'] ?? '');

        if ($secret === '' || $signature === null) {
            return false;
        }

        $algorithm = (string) ($config['algorithm'] ?? 'sha256');

        if (($config['scheme'] ?? 'hmac') === 'svix') {
            return $this->verifySvix($config, $rawBody, $signature, $request, $secret, $algorithm);
        }

        /*
         * Some providers sign the body, others sign a timestamp concatenated
         * with it. The signed string is built from config so a provider change
         * is a config change — and so the timestamped variant cannot be
         * accidentally verified as the plain one, which would accept a replay.
         */
        $timestampHeader = (string) ($config['timestamp_header'] ?? '');
        $signed = $timestampHeader !== ''
            ? $this->headerValue($request, $timestampHeader).$rawBody
            : $rawBody;

        // hash_equals, not ===. A timing-safe comparison, for the same reason
        // the Paystack handler uses one.
        return hash_equals(hash_hmac($algorithm, $signed, $secret), $signature);
    }

    /**
     * Svix, which is how Resend signs.
     *
     * The secret arrives as `whsec_<base64>`; the bytes under the prefix are
     * the key. The signed string is `{id}.{timestamp}.{body}`, so a captured
     * signature fits exactly one delivery of exactly one payload. The header
     * holds one or more space-separated `v1,<base64>` values — more than one
     * while a secret is being rotated — and any one matching is enough. A
     * timestamp outside the tolerance is refused even with a good signature:
     * that is what stops a genuine old event being replayed to re-suppress
     * an address that has since been released.
     *
     * @param  array<string, mixed>  $config
     */
    private function verifySvix(
        array $config,
        string $rawBody,
        string $signature,
        Request $request,
        string $secret,
        string $algorithm,
    ): bool {
        $id = $this->headerValue($request, (string) ($config['id_header'] ?? 'svix-id'));
        $timestamp = $this->headerValue($request, (string) ($config['timestamp_header'] ?? 'svix-timestamp'));

        if ($id === null || $timestamp === null || ! ctype_digit($timestamp)) {
            return false;
        }

        $tolerance = (int) ($config['tolerance_seconds'] ?? 300);

        if (abs(time() - (int) $timestamp) > $tolerance) {
            return false;
        }

        $key = base64_decode(Str::after($secret, 'whsec_'), true);

        if ($key === false || $key === '') {
            return false;
        }

        $expected = base64_encode(hash_hmac($algorithm, $id.'.'.$timestamp.'.'.$rawBody, $key, true));

        foreach (preg_split('/\s+/', trim($signature)) ?: [] as $candidate) {
            [$version, $value] = array_pad(explode(',', $candidate, 2), 2, '');

            if ($version === 'v1' && hash_equals($expected, $value)) {
                return true;
            }
        }

        return false;
    }

    private function headerValue(Request $request, string $header): ?string
    {
        if ($header === '') {
            return null;
        }

        $value = $request->header($header);

        return is_array($value) ? ($value[0] ?? null) : $value;
    }
}
