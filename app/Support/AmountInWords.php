<?php

declare(strict_types=1);

namespace App\Support;

use App\ValueObjects\Money;
use InvalidArgumentException;
use RuntimeException;

/**
 * Spells a monetary amount in words, for the donation acknowledgement.
 *
 * The GRA acknowledgement carries the amount twice — in figures and in words —
 * which is the standard defence against a digit being altered on a document
 * that will be handed to a tax authority by a third party.
 *
 * **Why this is not `Number::spell()` or `NumberFormatter`.** Both depend on
 * ext-intl, which is not guaranteed on shared cPanel hosting, and both produce
 * lowercase locale-driven output that varies with the CLDR version bundled in
 * the PHP build. A legal document whose wording changes when the host upgrades
 * PHP is not acceptable, so the conversion is done here: no extension, no
 * locale data, deterministic forever.
 *
 * Style is the Ghanaian/British convention used on cheques and legal
 * instruments — title case, "and" before a trailing sub-hundred group, hyphens
 * in compound tens:
 *
 *     Money::ofMajor('1234.56')  ->  One Thousand Two Hundred and Thirty-Four
 *                                    Ghana Cedis and Fifty-Six Pesewas
 */
final class AmountInWords
{
    /** @var array<int, string> */
    private const ONES = [
        0 => 'Zero', 1 => 'One', 2 => 'Two', 3 => 'Three', 4 => 'Four',
        5 => 'Five', 6 => 'Six', 7 => 'Seven', 8 => 'Eight', 9 => 'Nine',
        10 => 'Ten', 11 => 'Eleven', 12 => 'Twelve', 13 => 'Thirteen',
        14 => 'Fourteen', 15 => 'Fifteen', 16 => 'Sixteen', 17 => 'Seventeen',
        18 => 'Eighteen', 19 => 'Nineteen',
    ];

    /** @var array<int, string> */
    private const TENS = [
        2 => 'Twenty', 3 => 'Thirty', 4 => 'Forty', 5 => 'Fifty',
        6 => 'Sixty', 7 => 'Seventy', 8 => 'Eighty', 9 => 'Ninety',
    ];

    /** @var array<int, array{int, string}> */
    private const SCALES = [
        [1000000000000, 'Trillion'],
        [1000000000, 'Billion'],
        [1000000, 'Million'],
        [1000, 'Thousand'],
    ];

    /**
     * Currency unit names: [major singular, major plural, minor singular, minor plural].
     *
     * Only currencies the Foundation could genuinely issue a document in are
     * listed. Anything else throws rather than guessing a unit name onto a
     * legal instrument.
     *
     * @var array<string, array{string, string, string, string}>
     */
    private const UNITS = [
        'GHS' => ['Ghana Cedi', 'Ghana Cedis', 'Pesewa', 'Pesewas'],
        'USD' => ['US Dollar', 'US Dollars', 'Cent', 'Cents'],
        'GBP' => ['Pound Sterling', 'Pounds Sterling', 'Penny', 'Pence'],
        'EUR' => ['Euro', 'Euros', 'Cent', 'Cents'],
    ];

    /**
     * A monetary amount in words, including its currency units.
     *
     * @param  bool  $only  append "Only", the cheque convention that stops
     *                      anything being written after the amount
     */
    public static function money(Money $amount, bool $only = false): string
    {
        if ($amount->isNegative()) {
            // A negative amount on an acknowledgement is a bug upstream, and
            // spelling it would paper over that.
            throw new InvalidArgumentException('An amount in words cannot be negative.');
        }

        $units = self::UNITS[$amount->currency] ?? throw new RuntimeException(
            "No unit names defined for [{$amount->currency}]. Add them before issuing a "
            .'document in this currency rather than guessing what its units are called.'
        );

        [$whole, $fraction] = array_pad(explode('.', $amount->toMajorString(), 2), 2, '');

        $major = (int) $whole;
        $minor = $fraction === '' ? 0 : (int) $fraction;

        $parts = [];

        // Zero cedis with pesewas reads "Fifty Pesewas", not "Zero Ghana Cedis
        // and Fifty Pesewas" — but a zero total must still say something.
        if ($major > 0 || $minor === 0) {
            $parts[] = self::integer($major).' '.($major === 1 ? $units[0] : $units[1]);
        }

        if ($minor > 0) {
            $parts[] = self::integer($minor).' '.($minor === 1 ? $units[2] : $units[3]);
        }

        $out = implode(' and ', $parts);

        return $only ? $out.' Only' : $out;
    }

    /** A non-negative integer in words. */
    public static function integer(int $number): string
    {
        if ($number < 0) {
            throw new InvalidArgumentException('Cannot spell a negative integer.');
        }

        if ($number < 20) {
            return self::ONES[$number];
        }

        if ($number < 100) {
            $tens = self::TENS[intdiv($number, 10)];
            $rest = $number % 10;

            return $rest === 0 ? $tens : $tens.'-'.self::ONES[$rest];
        }

        if ($number < 1000) {
            $out = self::ONES[intdiv($number, 100)].' Hundred';
            $rest = $number % 100;

            return $rest === 0 ? $out : $out.' and '.self::integer($rest);
        }

        foreach (self::SCALES as [$value, $name]) {
            if ($number < $value) {
                continue;
            }

            $out = self::integer(intdiv($number, $value)).' '.$name;
            $rest = $number % $value;

            if ($rest === 0) {
                return $out;
            }

            // "and" only before a trailing group below a hundred:
            //   1,050  -> One Thousand and Fifty
            //   1,500  -> One Thousand Five Hundred
            return $out.($rest < 100 ? ' and ' : ' ').self::integer($rest);
        }

        // Unreachable: every number >= 1000 matches the Thousand scale, and
        // larger ones recurse through it (5,000 trillion spells correctly).
        throw new RuntimeException("Number [{$number}] is beyond the supported scale.");
    }
}
