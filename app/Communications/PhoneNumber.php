<?php

declare(strict_types=1);

namespace App\Communications;

use InvalidArgumentException;

/**
 * Normalises Ghanaian mobile numbers to E.164, and says which network one is on.
 *
 * Numbers arrive from donors, volunteers and beneficiaries in every shape a
 * person can type one: `024 123 4567`, `+233 24 123 4567`, `00233241234567`,
 * `233241234567`, `0241234567`. They are all the same phone. Stored as typed,
 * they are five different rows, five different suppression misses, and five
 * chances to text somebody who asked not to be texted.
 *
 * So everything is normalised at the boundary, once, to `+233241234567`.
 *
 * ── The ten-digit rule ──────────────────────────────────────────────────────
 *
 * Ghana went to ten-digit national numbers in 2010 — a leading `0` plus a
 * three-digit prefix plus seven digits. Old nine-digit numbers still circulate
 * in spreadsheets and on old letterheads, and they are NOT recoverable by
 * adding a zero: the migration inserted a digit into the middle. A nine-digit
 * number is rejected rather than guessed at, because a guessed phone number
 * sends somebody else's donation receipt to a stranger.
 */
class PhoneNumber
{
    public const NETWORK_UNKNOWN = 'unknown';

    /**
     * Normalise to E.164, or throw.
     *
     * Throws rather than returning null because every caller here is about to
     * send something to this number, and a silent null becomes a message that
     * never went with nobody knowing why.
     */
    public static function normalise(string $input): string
    {
        $digits = preg_replace('/\D+/', '', $input) ?? '';
        $cc = (string) config('communications.sms.country_code', '233');

        // 00233… international prefix
        if (str_starts_with($digits, '00'.$cc)) {
            $digits = substr($digits, 2);
        }

        // 233… already country-coded
        if (str_starts_with($digits, $cc)) {
            $national = '0'.substr($digits, strlen($cc));
        } elseif (str_starts_with($digits, '0')) {
            $national = $digits;
        } else {
            // Bare subscriber number, no trunk zero: 241234567 is nine digits
            // and ambiguous with a pre-2010 number, so it is only accepted at
            // the full nine-digit length after a known prefix is prepended —
            // which we cannot do. Treat it as national and let the length
            // check below reject it if it is wrong.
            $national = '0'.$digits;
        }

        if (! preg_match('/^0\d{9}$/', $national)) {
            throw new InvalidArgumentException(
                "[{$input}] is not a valid Ghanaian mobile number. Expected ten digits "
                .'beginning with 0, for example 0241234567. Nine-digit pre-2010 numbers '
                .'cannot be converted automatically — the network inserted a digit, so '
                .'guessing one would produce somebody else\'s number.'
            );
        }

        return '+'.$cc.substr($national, 1);
    }

    /** Normalise, or null. For validation and bulk import, where throwing is noise. */
    public static function tryNormalise(?string $input): ?string
    {
        if ($input === null || trim($input) === '') {
            return null;
        }

        try {
            return self::normalise($input);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public static function isValid(?string $input): bool
    {
        return self::tryNormalise($input) !== null;
    }

    /**
     * Which network, from the prefix.
     *
     * Not used for routing — the provider does that. It is here so an SMS
     * invoice can be checked against our own log per network, which is the only
     * practical way to notice that messages to one network are being accepted
     * and dropped.
     *
     * Number portability means the prefix is the ORIGINAL network, not
     * necessarily the current one. Good enough for cost attribution, not good
     * enough to make a routing decision on — which is why nothing routes on it.
     */
    public static function network(string $input): string
    {
        $e164 = self::tryNormalise($input);

        if ($e164 === null) {
            return self::NETWORK_UNKNOWN;
        }

        $cc = (string) config('communications.sms.country_code', '233');
        $prefix = '0'.substr($e164, strlen($cc) + 1, 2);

        /** @var array<string, array<int, string>> $networks */
        $networks = config('communications.sms.networks', []);

        foreach ($networks as $network => $prefixes) {
            if (in_array($prefix, $prefixes, true)) {
                return $network;
            }
        }

        return self::NETWORK_UNKNOWN;
    }

    /**
     * How the number is shown to a person: `024 123 4567`.
     *
     * Ghanaians read their own numbers in national format. Displaying
     * +233241234567 back to somebody checking their own details invites them to
     * "correct" it.
     */
    public static function forDisplay(string $input): string
    {
        $e164 = self::tryNormalise($input);

        if ($e164 === null) {
            return $input;
        }

        $cc = (string) config('communications.sms.country_code', '233');
        $national = '0'.substr($e164, strlen($cc) + 1);

        return substr($national, 0, 3).' '.substr($national, 3, 3).' '.substr($national, 6);
    }
}
