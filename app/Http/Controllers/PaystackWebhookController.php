<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\ProcessPaymentWebhook;
use App\Payments\PaymentManager;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The webhook endpoint. Payment truth arrives here, never on a browser redirect.
 *
 * The redirect tells us the donor came back to the site. It does not tell us
 * the money arrived — the donor can close the tab, lose signal, or edit the
 * URL. Only this endpoint, authenticated by HMAC, is evidence.
 *
 * The handler does as little as possible:
 *
 *   1. read the RAW body
 *   2. store it, before parsing, with the signature verdict
 *   3. queue the work
 *   4. answer 200
 *
 * **Always 200, even on a bad signature.** Paystack retries anything that is
 * not a 2xx, so answering 401 to a forged request buys nothing and turns a
 * probe into a retry storm. The rejection is recorded; the response is bland.
 *
 * The work is queued rather than done inline because Paystack times out on a
 * slow endpoint and retries — and doing the work inline is precisely how one
 * slow receipt render becomes four duplicate deliveries.
 */
class PaystackWebhookController extends Controller
{
    public function __invoke(Request $request, PaymentManager $payments): Response
    {
        /*
         * getContent(), not $request->all(). The signature is computed over the
         * exact bytes Paystack sent; decoding and re-encoding produces a
         * different string and every signature check would fail.
         */
        $rawBody = $request->getContent();
        $signature = $request->header('x-paystack-signature');

        try {
            $event = $payments->recordWebhook(
                rawBody: $rawBody,
                signature: is_array($signature) ? ($signature[0] ?? null) : $signature,
                sourceIp: $request->ip(),
            );
        } catch (Throwable $e) {
            /*
             * Storing failed — a database problem, almost certainly. This is
             * the one case where a non-200 is right: Paystack should retry,
             * because the event has NOT been recorded and would otherwise be
             * lost entirely.
             */
            Log::critical('Could not record an incoming payment webhook.', [
                'error' => $e->getMessage(),
            ]);

            return response('', 500);
        }

        if ($event->signature_valid && ! $event->isProcessed()) {
            ProcessPaymentWebhook::dispatch($event->id);
        }

        return response('', 200);
    }
}
