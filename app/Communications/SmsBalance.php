<?php

declare(strict_types=1);

namespace App\Communications;

/**
 * What is left in the SMS account, with its unit.
 *
 * `credits` is one message-segment prepaid; `GHS`/`USD` is money. The
 * threshold in config is in whichever unit the driver reports, and the
 * health page and the alert say the unit rather than a bare number.
 */
final readonly class SmsBalance
{
    public function __construct(
        public float $amount,
        public string $unit,
    ) {}

    public static function credits(int|float $amount): self
    {
        return new self((float) $amount, 'credits');
    }

    public static function money(float $amount, string $currency): self
    {
        return new self($amount, strtoupper($currency));
    }

    public function isLow(float $threshold): bool
    {
        return $this->amount <= $threshold;
    }

    public function format(): string
    {
        return $this->unit === 'credits'
            ? number_format($this->amount).' '.__('credits')
            : $this->unit.' '.number_format($this->amount, 2);
    }
}
