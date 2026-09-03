<?php

declare(strict_types=1);

namespace App\Support;

use App\ValueObjects\Money;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use RuntimeException;

/**
 * Turns identifiable values into their generalised, non-identifying form.
 *
 * The premise, from Act 843's definition of personal data: a person can be
 * identifiable from retained data alone OR from that data combined with other
 * information the Foundation holds or is likely to hold. So stripping names is
 * not enough. An exact amount, an exact day and a village name together single
 * somebody out with no name present anywhere.
 *
 * This class does the coarsening. It has three jobs:
 *
 *   - band a value (age, amount) so it describes a group, not a person
 *   - reduce a date to a period
 *   - overwrite a value irreversibly, for the destroy disposition
 *
 * On "irreversibly": Act 843 s.24 requires destruction to prevent
 * reconstruction in an intelligible form. What this class can guarantee is the
 * LIVE row — after the write, the value is not in it. What it cannot reach is
 * the undo log, the binary log and yesterday's backup, all of which still hold
 * the old value for as long as those are retained. That gap is closed by backup
 * rotation and binlog expiry, which is a hosting configuration matter, not
 * something a PHP class can assert. Saying so here rather than implying a
 * completeness the code does not have.
 */
final class Anonymiser
{
    /** Marks a value that has already been overwritten. */
    public const REDACTION_PREFIX = '[redacted:';

    /**
     * Which disposition applies to a privacy element.
     *
     * An element absent from the policy is a configuration ERROR, not a
     * permission. The failure mode of guessing "keep" is that an unclassified
     * identifier survives de-identification, so an unknown element throws.
     */
    public function disposition(string $element): string
    {
        $config = config("compliance.privacy.elements.{$element}");

        if ($config === null) {
            throw new RuntimeException(
                "No privacy disposition defined for element [{$element}]. "
                .'Every field holding beneficiary data must be classified as destroy, '
                .'generalise or keep before it can be de-identified.'
            );
        }

        return $config['disposition'];
    }

    public function mustDestroy(string $element): bool
    {
        return $this->disposition($element) === 'destroy';
    }

    /**
     * A replacement value that irreversibly overwrites the original.
     *
     * Random rather than a fixed sentinel: a column full of identical
     * placeholders still reveals which rows were de-identified and when, and
     * that pattern is itself a small disclosure.
     */
    public function overwriteValue(int $length = 24): string
    {
        return self::REDACTION_PREFIX.bin2hex(random_bytes(max(4, intdiv($length, 2)))).']';
    }

    /**
     * Whether a value has already been overwritten.
     *
     * Needed for idempotence: a NOT NULL column cannot be emptied, so it holds
     * a marker rather than null, and without this check a re-run would replace
     * that marker with a fresh random one. The data stays redacted either way,
     * but a retention run that churns rows every time it passes over them makes
     * the audit trail harder to read, not easier.
     */
    public function isRedacted(mixed $value): bool
    {
        return is_string($value) && str_starts_with($value, self::REDACTION_PREFIX);
    }

    /**
     * An age band label, from an age in years or a date of birth.
     *
     * Returns null for an unknown age rather than inventing a band — a wrong
     * band in an impact report is worse than an absent one.
     */
    public function ageBand(int|DateTimeInterface|null $age, ?DateTimeInterface $on = null): ?string
    {
        if ($age === null) {
            return null;
        }

        if ($age instanceof DateTimeInterface) {
            $reference = $on ? Carbon::instance($on) : now();
            $age = (int) Carbon::instance($age)->diffInYears($reference);
        }

        if ($age < 0) {
            return null;
        }

        foreach ((array) config('compliance.privacy.age_bands', []) as [$from, $to]) {
            if ($age >= $from && ($to === null || $age <= $to)) {
                return $to === null ? "{$from}+" : "{$from}-{$to}";
            }
        }

        return null;
    }

    /**
     * An amount band label for a monetary value.
     *
     * Takes integer pesewas or a Money, and returns a GHS range in major units
     * — the band is for a human reading an impact report, not for arithmetic.
     */
    public function amountBand(Money|int|null $amount): ?string
    {
        if ($amount === null) {
            return null;
        }

        $minor = $amount instanceof Money ? $amount->toMinor() : $amount;

        if ($minor < 0) {
            return null;
        }

        foreach ((array) config('compliance.privacy.amount_bands', []) as [$from, $to]) {
            if ($minor >= $from && ($to === null || $minor <= $to)) {
                $lower = Money::ofMinor((int) $from)->format(false);

                return $to === null
                    ? "Above {$lower}"
                    : $lower.' - '.Money::ofMinor((int) $to + 1)->format(false);
            }
        }

        return null;
    }

    /**
     * A date reduced to the configured granularity.
     *
     * `2026-03-13` becomes `2026-03` at month precision, `2026-Q1` at quarter,
     * `2026` at year. An exact day plus a division plus a district is frequently
     * unique; a month is not.
     */
    public function period(?DateTimeInterface $date, ?string $granularity = null): ?string
    {
        if ($date === null) {
            return null;
        }

        $date = Carbon::instance($date);
        $granularity ??= (string) config('compliance.privacy.date_granularity', 'month');

        return match ($granularity) {
            'year' => $date->format('Y'),
            'quarter' => $date->format('Y').'-Q'.$date->quarter,
            'month' => $date->format('Y-m'),
            default => throw new InvalidArgumentException(
                "Unknown date granularity [{$granularity}]. Use month, quarter or year."
            ),
        };
    }

    /**
     * Whether a geographic level is coarse enough to publish.
     *
     * Region and district cover populations large enough to hide in; a village
     * or a community does not, which is why `community` is a destroy element
     * rather than a generalise one.
     */
    public function geographyPermitted(string $level): bool
    {
        $order = ['country', 'region', 'district', 'community', 'address'];
        $max = (string) config('compliance.privacy.geography_max_level', 'district');

        $levelIndex = array_search($level, $order, true);
        $maxIndex = array_search($max, $order, true);

        if ($levelIndex === false) {
            throw new InvalidArgumentException("Unknown geographic level [{$level}].");
        }

        return $levelIndex <= $maxIndex;
    }

    /**
     * Apply the configured method for a generalise element.
     *
     * @param  mixed  $value  the exact value being coarsened
     */
    public function generalise(string $element, mixed $value, ?DateTimeInterface $on = null): mixed
    {
        if ($this->disposition($element) !== 'generalise') {
            throw new RuntimeException(
                "Element [{$element}] is not a generalise element; "
                .'calling this on it would silently keep an exact value.'
            );
        }

        $method = config("compliance.privacy.elements.{$element}.method", 'passthrough');

        return match ($method) {
            'age_band' => $this->ageBand($value, $on),
            'amount_band' => $this->amountBand($value),
            'period' => $this->period($value),
            'passthrough' => $value,
            default => throw new InvalidArgumentException(
                "Unknown generalisation method [{$method}] for element [{$element}]."
            ),
        };
    }
}
