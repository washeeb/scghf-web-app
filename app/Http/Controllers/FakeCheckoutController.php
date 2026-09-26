<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Payments\FakeGateway;
use App\Support\PageMeta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The sandbox checkout, standing in for Paystack.
 *
 * ── Why this had to be built ────────────────────────────────────────────────
 *
 * ⚠ `FakeGateway::initialise()` has returned `url('/payments/fake/{reference}')`
 * as its authorization URL since Phase 3, and that route did not exist. `fake`
 * is the DEFAULT payment driver, so on every developer machine, in CI and on
 * any staging deployment without live keys, starting a donation sent the donor
 * to a 404. The donation engine was fully tested and the donation JOURNEY could
 * not be walked once, by anybody.
 *
 * ── It refuses to exist in production ───────────────────────────────────────
 *
 * Two independent guards, and either is enough: the route is not registered
 * when the app is in production, and this controller aborts anyway. A page that
 * can mark a payment successful without money changing hands is the single most
 * dangerous thing in this repository, and "the route isn't registered" is not a
 * strong enough answer on its own — a stale route cache, a mis-set `APP_ENV`,
 * and it would be.
 *
 * ── It goes through the real webhook path ───────────────────────────────────
 *
 * Not by calling `onPaymentSettled()` directly. The fake gateway signs its
 * payload with the same HMAC-SHA512 the real one uses, so pressing "pay" here
 * exercises signature verification, the idempotent event store and the queued
 * processing — which is the whole point of having a fake gateway rather than a
 * mock.
 */
class FakeCheckoutController extends Controller
{
    public function show(string $reference): View
    {
        $transaction = $this->transaction($reference);

        return view('payments.fake', [
            'transaction' => $transaction,
            'meta' => PageMeta::site(__('Sandbox payment'), noindex: true),
        ]);
    }

    /**
     * Simulate the donor paying, or failing to.
     *
     * The failure path matters as much as the success one: a foundation needs
     * to know what a declined card looks like to the person holding it, and
     * that is not something to discover from a real donor's complaint.
     */
    public function pay(Request $request, string $reference): RedirectResponse
    {
        $transaction = $this->transaction($reference);

        $outcome = $request->string('outcome')->toString();

        app(FakeGateway::class)->deliverWebhook(
            $transaction,
            successful: $outcome !== 'fail',
        );

        /*
         * Back to whichever journey started the payment. The sandbox stood in
         * for the gateway, and the gateway sends the customer to the callback
         * the transaction was initialised with — a donor to the donation page,
         * a shopper to their order.
         */
        $callback = $transaction->payable instanceof Order
            ? route('shop.checkout.callback', ['reference' => $transaction->gateway_reference])
            : route('donate.callback', ['reference' => $transaction->gateway_reference]);

        return redirect()->to($callback);
    }

    private function transaction(string $reference): PaymentTransaction
    {
        /*
         * Belt and braces with the route registration. See the note at the top:
         * a page that can mark a payment successful must not be one line of
         * config away from existing on the live site.
         */
        abort_if(app()->isProduction(), 404);

        return PaymentTransaction::query()
            ->where('gateway_reference', $reference)
            ->where('gateway', PaymentTransaction::GATEWAY_FAKE)
            ->first() ?? throw new NotFoundHttpException;
    }
}
