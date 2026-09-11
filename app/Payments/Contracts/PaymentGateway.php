<?php

declare(strict_types=1);

namespace App\Payments\Contracts;

use App\Models\PaymentTransaction;
use App\Models\Refund;
use App\Payments\GatewayResult;

/**
 * What a payment gateway has to be able to do.
 *
 * The interface exists so the entire payments module can be built and tested
 * before the Paystack merchant account is open — which is the situation this
 * project is actually in. `FakeGateway` returns deterministic fixtures; every
 * line of donation, receipt and reconciliation logic above this boundary is the
 * same either way.
 *
 * Implementations return a normalised `GatewayResult`, never a raw HTTP
 * response. Letting Paystack's payload shape leak upwards would put gateway
 * knowledge in the donation code, which is exactly the coupling that makes
 * changing or adding a provider a rewrite.
 */
interface PaymentGateway
{
    /** `paystack`, `fake`, `offline`. */
    public function name(): string;

    /**
     * Start a charge and get somewhere to send the donor.
     *
     * @param  array<string, mixed>  $options  channels, metadata, callback URL
     */
    public function initialise(PaymentTransaction $transaction, array $options = []): GatewayResult;

    /**
     * Charge an instrument the donor has already authorised.
     *
     * How a recurring gift is taken without the donor being present. The
     * authorization code is a gateway token, not card data — it is meaningless
     * outside this merchant account.
     *
     * Unlike `initialise()` this settles immediately or fails immediately:
     * there is nowhere to send the donor, because the donor is not there.
     */
    public function chargeAuthorization(PaymentTransaction $transaction, string $authorizationCode): GatewayResult;

    /**
     * Ask the gateway what actually happened.
     *
     * Called from the webhook handler and from reconciliation. Never trusted
     * from the browser redirect: the redirect tells us the donor came back, not
     * that the money arrived.
     */
    public function verify(string $gatewayReference): GatewayResult;

    /**
     * Charge a mobile-money wallet directly, without the hosted page.
     *
     * The donor is prompted on their handset. The result is usually an
     * awaiting state (`pay_offline`, `send_otp`) rather than a settlement; the
     * webhook, or a verify, says whether the money came.
     *
     * @param  string  $provider  the gateway's code for the network: mtn, vod, atl
     * @param  string  $phone  normalised, +233…
     */
    public function chargeMobileMoney(PaymentTransaction $transaction, string $provider, string $phone): GatewayResult;

    /** Submit the one-time code a network texted the donor during a direct charge. */
    public function submitOtp(PaymentTransaction $transaction, string $otp): GatewayResult;

    /** Send money back. */
    public function refund(Refund $refund): GatewayResult;

    /**
     * Whether a webhook body genuinely came from the gateway.
     *
     * Takes the RAW body, not a parsed array: re-encoding JSON changes the
     * bytes, and the signature is over the bytes.
     */
    public function verifySignature(string $rawBody, ?string $signature): bool;
}
