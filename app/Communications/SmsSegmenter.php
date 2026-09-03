<?php

declare(strict_types=1);

namespace App\Communications;

/**
 * Works out what an SMS will actually cost before it is sent.
 *
 * An SMS is not "160 characters". It is 160 characters *if every character is
 * in the GSM 03.38 alphabet*. One character outside it switches the whole
 * message to UCS-2, where a segment holds 70 characters — so a single stray
 * character can turn a one-segment message into a three-segment one for every
 * recipient, permanently, until somebody notices the bill.
 *
 * ── The one that matters here ───────────────────────────────────────────────
 *
 * The Ghana cedi sign ₵ (U+20B5) is NOT in GSM-7, and is not in the extension
 * table either. Writing the project's own display format "GH₵ 50.00" into an
 * SMS template therefore costs roughly three times as much per message as
 * writing "GHS 50.00", for one character nobody reads differently.
 *
 * That is not a hypothetical: CLAUDE.md mandates "GH₵ 1,234.56" as the display
 * format, so the correct format for the website is the expensive format for
 * SMS. This class exists so that trade-off is visible in the template editor
 * rather than discovered on an invoice.
 *
 * Also worth knowing, and handled below:
 *   - the extension characters ^ { } \ [ ~ ] | € each cost TWO GSM-7 septets
 *   - a multipart message loses 7 bits per segment to the concatenation header,
 *     so segments are 153 (GSM-7) or 67 (UCS-2), not 160 and 70
 *   - characters outside the Basic Multilingual Plane (emoji) take two UCS-2
 *     code units each
 */
class SmsSegmenter
{
    /**
     * GSM 03.38 basic character set. Each of these costs one septet.
     *
     * Held as a string rather than an array because the check is a substring
     * search per character and this runs over every template on every save.
     */
    private const GSM7_BASIC = "@\u{A3}\$\u{A5}\u{E8}\u{E9}\u{F9}\u{EC}\u{F2}\u{C7}\n\u{D8}\u{F8}\r\u{C5}\u{E5}"
        ."\u{394}_\u{3A6}\u{393}\u{39B}\u{3A9}\u{3A0}\u{3A8}\u{3A3}\u{398}\u{39E}"
        ."\u{C6}\u{E6}\u{DF}\u{C9} !\"#\u{A4}%&'()*+,-./0123456789:;<=>?"
        ."\u{A1}ABCDEFGHIJKLMNOPQRSTUVWXYZ\u{C4}\u{D6}\u{D1}\u{DC}\u{A7}"
        ."\u{BF}abcdefghijklmnopqrstuvwxyz\u{E4}\u{F6}\u{F1}\u{FC}\u{E0}";

    /**
     * GSM 03.38 extension table. Encodable, but each costs TWO septets because
     * it is sent as an escape character followed by the code.
     */
    private const GSM7_EXTENDED = "^{}\\[~]|\u{20AC}";

    public const ENCODING_GSM7 = 'gsm7';

    public const ENCODING_UCS2 = 'ucs2';

    /**
     * Measure a message.
     *
     * @return array{
     *     encoding: string,
     *     characters: int,
     *     units: int,
     *     segments: int,
     *     per_segment: int,
     *     remaining: int,
     *     forced_ucs2_by: array<int, string>
     * }
     */
    public function measure(string $body): array
    {
        $characters = mb_strlen($body, 'UTF-8');
        $offenders = $this->charactersForcingUcs2($body);
        $encoding = $offenders === [] ? self::ENCODING_GSM7 : self::ENCODING_UCS2;

        $units = $encoding === self::ENCODING_GSM7
            ? $this->septets($body)
            : $this->utf16Units($body);

        $limits = config("communications.sms.segments.{$encoding}");
        $single = (int) $limits['single'];
        $multi = (int) $limits['multipart'];

        // A message that fits in one segment is not multipart, so it gets the
        // full allowance. The moment it does not, EVERY segment pays the
        // concatenation header — which is why 161 characters is 2 segments of
        // 153, not 160 + 1.
        $segments = $units <= $single ? 1 : (int) ceil($units / $multi);
        $perSegment = $segments <= 1 ? $single : $multi;

        return [
            'encoding' => $encoding,
            'characters' => $characters,
            'units' => $units,
            'segments' => max(1, $segments),
            'per_segment' => $perSegment,
            'remaining' => ($segments <= 1 ? $single : $segments * $multi) - $units,
            'forced_ucs2_by' => $offenders,
        ];
    }

    public function segments(string $body): int
    {
        return $this->measure($body)['segments'];
    }

    public function encoding(string $body): string
    {
        return $this->measure($body)['encoding'];
    }

    /**
     * Estimated cost in INTEGER PESEWAS, matching the money rule used
     * everywhere else in this application.
     *
     * An estimate, and the column that stores it is named to say so. The real
     * figure comes from the provider's invoice, and reconciling the two is how
     * a mis-set rate gets noticed.
     */
    public function estimatedCostMinor(string $body, int $recipients = 1): int
    {
        $rate = (int) config('communications.sms.cost_per_segment_minor', 4);

        return $this->segments($body) * max(0, $recipients) * $rate;
    }

    /**
     * The distinct characters that force the expensive encoding.
     *
     * Returned as a list so the template editor can say exactly which character
     * to change — "₵ costs you 3 segments" is actionable, "this message is
     * UCS-2" is not.
     *
     * @return array<int, string>
     */
    public function charactersForcingUcs2(string $body): array
    {
        $found = [];

        foreach (preg_split('//u', $body, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
            if (! $this->isGsm7($char) && ! in_array($char, $found, true)) {
                $found[] = $char;
            }
        }

        return $found;
    }

    /**
     * Whether the message would fit inside the configured segment ceiling.
     *
     * Three segments is three times the cost and, on a feature phone, three
     * separate arrivals that can turn up out of order. A message that will not
     * fit wants to be an email with an SMS pointing at it.
     */
    public function withinBudget(string $body, ?int $maxSegments = null): bool
    {
        $max = $maxSegments ?? (int) config('communications.sms.max_segments', 2);

        return $this->segments($body) <= $max;
    }

    /**
     * A short, human explanation of the measurement, for the template editor.
     */
    public function explain(string $body): string
    {
        $m = $this->measure($body);

        $line = sprintf(
            '%d characters · %s · %d segment%s',
            $m['characters'],
            $m['encoding'] === self::ENCODING_GSM7 ? 'GSM-7' : 'Unicode (UCS-2)',
            $m['segments'],
            $m['segments'] === 1 ? '' : 's',
        );

        if ($m['forced_ucs2_by'] !== []) {
            $line .= sprintf(
                '. %s not in the GSM alphabet, so every segment holds %d characters instead of %d.',
                implode(' ', array_map(fn (string $c): string => "[{$c}]", $m['forced_ucs2_by'])),
                (int) config('communications.sms.segments.ucs2.single'),
                (int) config('communications.sms.segments.gsm7.single'),
            );
        }

        return $line;
    }

    private function isGsm7(string $char): bool
    {
        return mb_strpos(self::GSM7_BASIC, $char, 0, 'UTF-8') !== false
            || mb_strpos(self::GSM7_EXTENDED, $char, 0, 'UTF-8') !== false;
    }

    /** GSM-7 length, counting extension characters as two. */
    private function septets(string $body): int
    {
        $count = 0;

        foreach (preg_split('//u', $body, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
            $count += mb_strpos(self::GSM7_EXTENDED, $char, 0, 'UTF-8') !== false ? 2 : 1;
        }

        return $count;
    }

    /**
     * UCS-2 length in 16-bit units.
     *
     * Anything outside the Basic Multilingual Plane — every emoji — is a
     * surrogate pair and occupies two units, which is why a message with four
     * emoji in it is shorter than it looks and costs more than it looks.
     */
    private function utf16Units(string $body): int
    {
        $count = 0;

        foreach (preg_split('//u', $body, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
            $code = mb_ord($char, 'UTF-8');
            $count += ($code !== false && $code > 0xFFFF) ? 2 : 1;
        }

        return $count;
    }
}
