<?php

declare(strict_types=1);

namespace App\Payments;

/**
 * Which money the payment layer is taking: none, pretend, or real.
 *
 * ── Three answers, not two ──────────────────────────────────────────────────
 *
 * "Test or live" is not enough, because the local and staging sites usually
 * run the fake gateway, which is neither: it takes no money and asks nobody.
 * So the mode is one of:
 *
 *   fake  — the in-process `FakeGateway`; every gift settles on request
 *   test  — Paystack with `sk_test_` keys; the sandbox, no money moves
 *   live  — Paystack with `sk_live_` keys; real cedis leave real accounts
 *
 * ── Read the same way Site Health reads it ─────────────────────────────────
 *
 * The driver from `payments.driver`, the mode from the secret key's prefix.
 * Site Health flags the wrong pairing (live keys off production, test keys
 * on it); this class only reports which pairing it is, so the admin banner
 * and the health check can never disagree about what they are looking at.
 */
enum PaymentMode: string
{
    case Fake = 'fake';
    case Test = 'test';
    case Live = 'live';

    public static function current(): self
    {
        if ((string) config('payments.driver', 'fake') !== 'paystack') {
            return self::Fake;
        }

        $secret = (string) config('payments.paystack.secret_key', '');

        return str_starts_with($secret, 'sk_live_') ? self::Live : self::Test;
    }

    public function isLive(): bool
    {
        return $this === self::Live;
    }

    /** The short label the banner shows. */
    public function label(): string
    {
        return match ($this) {
            self::Fake => __('No gateway'),
            self::Test => __('Test mode'),
            self::Live => __('Live'),
        };
    }

    /** One sentence on what that means for the numbers on the screen. */
    public function explanation(): string
    {
        return match ($this) {
            self::Fake => __('Payments are simulated by the application. No gateway is connected and no money moves. Every figure in Finance is practice data.'),
            self::Test => __('Payments go to the Paystack sandbox. Cards and mobile money are test numbers and no money moves. Every figure in Finance is practice data.'),
            self::Live => __('Payments are real.'),
        };
    }
}
