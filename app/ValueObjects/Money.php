<?php

declare(strict_types=1);

namespace App\ValueObjects;

use App\Casts\MoneyCast;
use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * An exact monetary amount, stored as an integer number of minor units.
 *
 * GH₵ 50.00 is 5000 pesewas. There is no float anywhere in this class, and no
 * public way to get one out, because `0.1 + 0.2 !== 0.3` and a donation ledger
 * cannot afford that. Every arithmetic operation is integer or bcmath.
 *
 * Instances are immutable — every operation returns a new Money.
 *
 * @see MoneyCast for reading and writing these on Eloquent models.
 */
final readonly class Money implements JsonSerializable, Stringable
{
    /**
     * Minor-unit exponent per currency. GHS is the only one this project
     * transacts in; the others exist so a mistake is caught rather than
     * silently treated as 2 decimal places.
     */
    private const EXPONENTS = [
        'GHS' => 2,
        'USD' => 2,
        'EUR' => 2,
        'GBP' => 2,
        'NGN' => 2,
        'KES' => 2,
        'ZAR' => 2,
        'XOF' => 0,
        'JPY' => 0,
    ];

    private const SYMBOLS = [
        'GHS' => 'GH₵',
        'USD' => '$',
        'EUR' => '€',
        'GBP' => '£',
        'NGN' => '₦',
    ];

    private function __construct(
        public int $minor,
        public string $currency,
    ) {}

    // ── Construction ─────────────────────────────────────────────────────────

    /**
     * Build from minor units — pesewas for GHS. This is the primary constructor
     * and the only one used when reading from the database or from Paystack.
     */
    public static function ofMinor(int $minor, string $currency = 'GHS'): self
    {
        $currency = self::normaliseCurrency($currency);

        return new self($minor, $currency);
    }

    /**
     * Build from a major-unit amount — cedis for GHS.
     *
     * Accepts a string ('50.00'), an int (50), or a float. Floats are permitted
     * only because form input and json_decode produce them; the value is routed
     * straight through a string conversion so no binary-fraction error survives.
     * Prefer passing a string wherever you control the caller.
     *
     * @throws InvalidArgumentException if the amount has more decimal places
     *                                  than the currency allows
     */
    public static function ofMajor(string|int|float $major, string $currency = 'GHS'): self
    {
        $currency = self::normaliseCurrency($currency);
        $exponent = self::EXPONENTS[$currency];

        // Normalise to a plain decimal string. sprintf on a float here is safe:
        // it rounds at a fixed precision rather than exposing the binary value.
        $string = is_float($major)
            ? sprintf('%.'.$exponent.'F', $major)
            : trim((string) $major);

        // Strip comma thousands separators and a leading +, so this round-trips
        // our own format() output ('1,234.56').
        //
        // Spaces are deliberately NOT stripped. Doing so would also silently
        // turn a typo like '5 0' into 50 — a different amount from the one the
        // donor typed. Refusing is the only safe behaviour in a money field.
        $string = str_replace([',', '+'], '', $string);

        if (! preg_match('/^-?\d+(\.\d+)?$/', $string)) {
            throw new InvalidArgumentException("Not a valid monetary amount: {$major}");
        }

        [$whole, $fraction] = array_pad(explode('.', $string, 2), 2, '');

        if (strlen($fraction) > $exponent) {
            // Refuse rather than round. If a caller means to round, it must say so.
            throw new InvalidArgumentException(
                "{$currency} allows {$exponent} decimal place(s); got '{$string}'."
            );
        }

        $negative = str_starts_with($whole, '-');
        $whole = ltrim($whole, '-');
        $fraction = str_pad($fraction, $exponent, '0');

        $minor = (int) ($whole.$fraction);

        return new self($negative ? -$minor : $minor, $currency);
    }

    public static function zero(string $currency = 'GHS'): self
    {
        return new self(0, self::normaliseCurrency($currency));
    }

    // ── Arithmetic ───────────────────────────────────────────────────────────

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor + $other->minor, $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor - $other->minor, $this->currency);
    }

    /** Multiply by a whole number — a line-item quantity, for instance. */
    public function times(int $factor): self
    {
        return new self($this->minor * $factor, $this->currency);
    }

    /**
     * A percentage of this amount, rounded half-up to the nearest minor unit.
     *
     * Used for the Paystack fee, which is a percentage with a cap. The rate is
     * a string ('1.95') so no float ever enters the calculation.
     */
    public function percentage(string $rate): self
    {
        if (! preg_match('/^\d+(\.\d+)?$/', $rate)) {
            throw new InvalidArgumentException("Not a valid percentage rate: {$rate}");
        }

        // (minor * rate / 100), rounded half-up, entirely in bcmath.
        $exact = bcdiv(bcmul((string) $this->minor, $rate, 10), '100', 10);
        $rounded = (int) bcadd($exact, '0.5', 0);

        return new self($rounded, $this->currency);
    }

    /** The smaller of two amounts — used to apply a fee cap. */
    public function min(self $other): self
    {
        $this->assertSameCurrency($other);

        return $this->minor <= $other->minor ? $this : $other;
    }

    public function max(self $other): self
    {
        $this->assertSameCurrency($other);

        return $this->minor >= $other->minor ? $this : $other;
    }

    /**
     * Split into $n parts that sum exactly back to this amount.
     *
     * Naive division loses pesewas to rounding; this distributes the remainder
     * one minor unit at a time across the earlier parts. Needed for split or
     * designated giving, where the parts must reconcile to the total exactly.
     *
     * @return array<int, self>
     */
    public function allocateEvenly(int $n): array
    {
        if ($n < 1) {
            throw new InvalidArgumentException('Cannot allocate into fewer than one part.');
        }

        return $this->allocate(array_fill(0, $n, 1));
    }

    /**
     * Split by integer ratios that sum exactly back to this amount.
     *
     * @param  array<int, int>  $ratios
     * @return array<int, self>
     */
    public function allocate(array $ratios): array
    {
        $total = array_sum($ratios);

        if ($total <= 0) {
            throw new InvalidArgumentException('Allocation ratios must sum to a positive number.');
        }

        $parts = [];
        $allocated = 0;

        foreach ($ratios as $ratio) {
            $share = intdiv($this->minor * $ratio, $total);
            $parts[] = $share;
            $allocated += $share;
        }

        // Hand the rounding remainder out one minor unit at a time.
        $remainder = $this->minor - $allocated;
        for ($i = 0; $remainder !== 0; $i = ($i + 1) % count($parts)) {
            $step = $remainder <=> 0;
            $parts[$i] += $step;
            $remainder -= $step;
        }

        return array_map(fn (int $m): self => new self($m, $this->currency), $parts);
    }

    // ── Comparison ───────────────────────────────────────────────────────────

    public function equals(self $other): bool
    {
        return $this->minor === $other->minor && $this->currency === $other->currency;
    }

    public function greaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minor > $other->minor;
    }

    public function greaterThanOrEqual(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minor >= $other->minor;
    }

    public function lessThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minor < $other->minor;
    }

    public function lessThanOrEqual(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minor <= $other->minor;
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    public function isPositive(): bool
    {
        return $this->minor > 0;
    }

    public function isNegative(): bool
    {
        return $this->minor < 0;
    }

    // ── Output ───────────────────────────────────────────────────────────────

    /** The amount in minor units — what goes to Paystack and into the database. */
    public function toMinor(): int
    {
        return $this->minor;
    }

    /**
     * The major-unit amount as a decimal STRING, e.g. '1234.56'.
     *
     * Deliberately not a float. Use this for display, for CSV export, and for
     * anywhere a human-readable number is needed.
     */
    public function toMajorString(): string
    {
        $exponent = self::EXPONENTS[$this->currency];

        if ($exponent === 0) {
            return (string) $this->minor;
        }

        $sign = $this->minor < 0 ? '-' : '';
        $abs = (string) abs($this->minor);
        $abs = str_pad($abs, $exponent + 1, '0', STR_PAD_LEFT);

        return $sign.substr($abs, 0, -$exponent).'.'.substr($abs, -$exponent);
    }

    /** Formatted for display: `GH₵ 1,234.56`. */
    public function format(bool $withSymbol = true): string
    {
        $exponent = self::EXPONENTS[$this->currency];
        [$whole, $fraction] = array_pad(explode('.', $this->toMajorString(), 2), 2, '');

        $negative = str_starts_with($whole, '-');
        $whole = ltrim($whole, '-');

        // Thousands separators inserted by string manipulation, not number_format,
        // which would require a float cast — the one thing this class promises
        // never to do with a monetary value.
        $grouped = strrev(implode(',', str_split(strrev($whole), 3)));

        $out = $grouped.($exponent > 0 ? '.'.$fraction : '');

        if ($negative) {
            $out = '-'.$out;
        }

        if (! $withSymbol) {
            return $out;
        }

        $symbol = self::SYMBOLS[$this->currency] ?? $this->currency;

        return $symbol.' '.$out;
    }

    public function __toString(): string
    {
        return $this->format();
    }

    /** @return array{minor: int, currency: string, formatted: string} */
    public function jsonSerialize(): array
    {
        return [
            'minor' => $this->minor,
            'currency' => $this->currency,
            'formatted' => $this->format(),
        ];
    }

    // ── Internals ────────────────────────────────────────────────────────────

    private static function normaliseCurrency(string $currency): string
    {
        $currency = strtoupper(trim($currency));

        if (! isset(self::EXPONENTS[$currency])) {
            throw new InvalidArgumentException("Unsupported currency: {$currency}");
        }

        return $currency;
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException(
                "Cannot combine {$this->currency} with {$other->currency}."
            );
        }
    }
}
