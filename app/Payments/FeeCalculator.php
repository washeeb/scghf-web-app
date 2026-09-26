<?php

declare(strict_types=1);

namespace App\Payments;

use App\ValueObjects\Money;
use RuntimeException;

/**
 * Paystack's fee, and the arithmetic for a donor covering it.
 *
 * **This is a model of the fee, not the fee.** What Paystack actually charged
 * comes back on the webhook and is what gets stored on the transaction;
 * reconciliation compares the two and the gateway wins. This class exists to
 * show a donor a number before they pay, and to gross up a gift when they tick
 * "cover the transaction fee".
 *
 * Every step is integer arithmetic on pesewas. The rate is held in BASIS POINTS
 * — 195, not 1.95 — precisely so no float ever enters the calculation. A rate
 * that is a float is a rate that eventually produces a gift of GH₵ 49.999999.
 *
 * The rate must be verified against the signed merchant agreement before
 * go-live: a wrong rate here under- or over-charges every donor who ticks the
 * box, and neither error is one anyone notices quickly.
 */
final class FeeCalculator
{
    public function __construct(
        private readonly int $percentBps,
        private readonly int $capMinor,
        private readonly int $flatMinor = 0,
    ) {
        if ($this->percentBps < 0 || $this->percentBps >= 10000) {
            throw new RuntimeException(
                "A fee rate of {$this->percentBps} basis points is not usable. "
                .'At 100% or more, no charge amount can ever net the intended gift.'
            );
        }
    }

    public static function fromConfig(): self
    {
        return new self(
            percentBps: (int) config('payments.fees.percent_bps', 195),
            capMinor: (int) config('payments.fees.cap_minor', 10000),
            flatMinor: (int) config('payments.fees.flat_minor', 0),
        );
    }

    /**
     * The fee charged on a given charge amount.
     *
     * Rounded half up at the last step, which is the direction that errs
     * towards the foundation quoting slightly more rather than slightly less —
     * the safer direction when the number is shown to a donor as "what we will
     * add".
     */
    public function on(Money $amount): Money
    {
        return Money::ofMinor($this->feeMinor($amount->toMinor()), $amount->currency);
    }

    /**
     * What actually reaches the foundation after the gateway takes its cut.
     */
    public function netOf(Money $amount): Money
    {
        return $amount->minus($this->on($amount));
    }

    /**
     * The amount to charge so that the foundation nets the intended gift.
     *
     * Not `intended + fee(intended)`. That is the mistake this method exists to
     * avoid: the fee is charged on the LARGER amount, so adding the fee on the
     * original figure always leaves the foundation short. Grossing up solves
     *
     *     charge - fee(charge) = intended
     *
     * Once the fee is capped the arithmetic is simply `intended + cap`, because
     * the fee stops growing with the charge.
     */
    public function grossUp(Money $intended): Money
    {
        if (! $intended->isPositive()) {
            return $intended;
        }

        $target = $intended->toMinor();

        /*
         * The capped case first. If charging `target + cap` would already hit
         * the cap, then the cap is what the gateway takes however much larger
         * the charge gets, and adding exactly the cap is the answer.
         */
        $capCandidate = $target + $this->capMinor;

        if ($this->capMinor > 0 && $this->feeMinor($capCandidate) >= $this->capMinor) {
            return Money::ofMinor($capCandidate, $intended->currency);
        }

        /*
         * Uncapped: integer ceiling of (target + flat) / (1 - rate), done
         * without a division that could produce a float.
         */
        $numerator = ($target + $this->flatMinor) * 10000;
        $denominator = 10000 - $this->percentBps;

        $charge = intdiv($numerator, $denominator) + ($numerator % $denominator === 0 ? 0 : 1);

        /*
         * Correct for the rounding inside feeMinor(). The estimate above solves
         * the continuous equation; the actual fee is rounded, so the result can
         * be a pesewa either side. These loops run once or twice at most — they
         * are exactness, not search.
         */
        while ($charge - $this->feeMinor($charge) < $target) {
            $charge++;
        }

        while ($charge > $target && ($charge - 1) - $this->feeMinor($charge - 1) >= $target) {
            $charge--;
        }

        return Money::ofMinor($charge, $intended->currency);
    }

    /**
     * What the donor is asked to add, shown beside the "cover the fee" tick box.
     */
    public function coverageFor(Money $intended): Money
    {
        return $this->grossUp($intended)->minus($intended);
    }

    /** The point above which the fee stops growing. */
    public function capReachedAt(): ?Money
    {
        if ($this->capMinor <= 0 || $this->percentBps === 0) {
            return null;
        }

        // Smallest charge whose percentage component reaches the cap.
        $minor = intdiv(($this->capMinor - $this->flatMinor) * 10000, $this->percentBps);

        return Money::ofMinor(max(0, $minor));
    }

    private function feeMinor(int $amountMinor): int
    {
        if ($amountMinor <= 0) {
            return 0;
        }

        // Round half up, integer only: (a * bps + 5000) / 10000.
        $percentComponent = intdiv($amountMinor * $this->percentBps + 5000, 10000);

        $fee = $percentComponent + $this->flatMinor;

        return $this->capMinor > 0 ? min($fee, $this->capMinor) : $fee;
    }
}
