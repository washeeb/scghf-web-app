<?php

declare(strict_types=1);

namespace App\Payments;

/**
 * Removes anything card-shaped from a payload before it is stored or logged.
 *
 * The PCI DSS SAQ-A posture rests on this application never holding card data.
 * Paystack does not send a PAN today — but a payload is stored verbatim for the
 * audit trail, and this is the boundary. A boundary that trusts the other side
 * not to change is not a boundary.
 *
 * Two rules:
 *   - a key on the configured list is replaced, at any depth
 *   - a VALUE that looks like a card number is replaced whatever its key,
 *     because the dangerous case is the field nobody anticipated
 */
final class PayloadScrubber
{
    public const REDACTED = '[scrubbed]';

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function scrub(array $payload): array
    {
        $keys = array_map('strtolower', (array) config('payments.webhooks.scrub_keys', []));

        return $this->walk($payload, $keys);
    }

    /**
     * @param  array<mixed>  $data
     * @param  array<int, string>  $keys
     * @return array<mixed>
     */
    private function walk(array $data, array $keys): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), $keys, true)) {
                $data[$key] = self::REDACTED;

                continue;
            }

            if (is_array($value)) {
                $data[$key] = $this->walk($value, $keys);

                continue;
            }

            if (is_string($value) && $this->looksLikeCardNumber($value)) {
                $data[$key] = self::REDACTED;
            }
        }

        return $data;
    }

    /**
     * Thirteen to nineteen digits, allowing the spaces and hyphens people type.
     *
     * Deliberately does NOT run a Luhn check. A value that merely looks like a
     * card number should be scrubbed whether or not it is a valid one — the
     * cost of a false positive is a redacted field in an audit log, and the
     * cost of a false negative is card data at rest.
     */
    private function looksLikeCardNumber(string $value): bool
    {
        $digits = preg_replace('/[\s-]/', '', $value) ?? '';

        return preg_match('/^\d{13,19}$/', $digits) === 1;
    }
}
