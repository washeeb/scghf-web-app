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
     * Ask the gateway what actually happened.
     *
     * Called from the webhook handler and from reconciliation. Never trusted
     * from the browser redirect: the redirect tells us the donor came back, not
     * that the money arrived.
     */
    public function verify(string $gatewayReference): GatewayResult;

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
