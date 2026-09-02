<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * WCAG 2.2 relative-luminance contrast.
 *
 * `CLAUDE.md` requires both themes to meet AA, and PHASE-1-BLUEPRINT.md §5
 * seeds a palette where every pair was checked. Those checks were done once, in
 * a document. This class makes them executable, so that:
 *
 *   1. The seeded palette is asserted by tests rather than trusted.
 *   2. The Filament theme editor can refuse a colour that breaks AA, instead of
 *      letting a well-meaning admin quietly make the donate button unreadable.
 *
 * Blueprint §5.5 records the pairs that FAIL and are therefore banned — white
 * on brand orange being the obvious trap at 3.03:1. This is what stops one of
 * them being reintroduced.
 */
class ContrastChecker
{
    /** Normal text. */
    public const AA_NORMAL = 4.5;

    /** Large text: >= 24px, or >= 18.66px bold. */
    public const AA_LARGE = 3.0;

    /** UI components and graphical objects (SC 1.4.11). */
    public const AA_NON_TEXT = 3.0;

    public const AAA_NORMAL = 7.0;

    /**
     * Contrast ratio between two hex colours, 1.0 to 21.0.
     *
     * Symmetric — order does not matter.
     */
    public function ratio(string $foreground, string $background): float
    {
        $l1 = $this->relativeLuminance($foreground);
        $l2 = $this->relativeLuminance($background);

        [$lighter, $darker] = $l1 >= $l2 ? [$l1, $l2] : [$l2, $l1];

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    /** Rounded to 2dp, which is how ratios are quoted in the blueprint. */
    public function ratioRounded(string $foreground, string $background): float
    {
        return round($this->ratio($foreground, $background), 2);
    }

    public function passes(string $foreground, string $background, float $threshold = self::AA_NORMAL): bool
    {
        // Compare on the rounded value so a pair quoted as "4.50:1" in the
        // blueprint is not rejected here by a floating-point hair.
        return $this->ratioRounded($foreground, $background) >= $threshold;
    }

    public function passesNormalText(string $fg, string $bg): bool
    {
        return $this->passes($fg, $bg, self::AA_NORMAL);
    }

    public function passesLargeText(string $fg, string $bg): bool
    {
        return $this->passes($fg, $bg, self::AA_LARGE);
    }

    public function passesNonText(string $fg, string $bg): bool
    {
        return $this->passes($fg, $bg, self::AA_NON_TEXT);
    }

    /**
     * The strongest WCAG level this pair satisfies, for display in the admin.
     *
     * @return 'AAA'|'AA'|'AA Large'|'Fail'
     */
    public function grade(string $foreground, string $background): string
    {
        $ratio = $this->ratioRounded($foreground, $background);

        return match (true) {
            $ratio >= self::AAA_NORMAL => 'AAA',
            $ratio >= self::AA_NORMAL => 'AA',
            $ratio >= self::AA_LARGE => 'AA Large',
            default => 'Fail',
        };
    }

    /**
     * Whether black or white text reads better on this background.
     *
     * Used to pick an automatic `on-` colour when an admin sets a custom brand
     * colour, so a new colour never arrives without a readable partner.
     */
    public function bestTextOn(string $background, string $dark = '#0F1A17', string $light = '#FFFFFF'): string
    {
        return $this->ratio($dark, $background) >= $this->ratio($light, $background)
            ? $dark
            : $light;
    }

    /**
     * WCAG relative luminance.
     *
     * The 0.03928 threshold and 2.4 exponent are from the spec, not arbitrary —
     * they model the sRGB transfer function.
     */
    public function relativeLuminance(string $hex): float
    {
        [$r, $g, $b] = $this->toRgb($hex);

        $channel = static function (int $value): float {
            $c = $value / 255;

            return $c <= 0.03928
                ? $c / 12.92
                : (($c + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $channel($r)
             + 0.7152 * $channel($g)
             + 0.0722 * $channel($b);
    }

    /**
     * Parse `#RGB`, `#RRGGBB`, or the same without the hash.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    public function toRgb(string $hex): array
    {
        $hex = ltrim(trim($hex), '#');

        if (strlen($hex) === 3) {
            // #abc -> #aabbcc
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        if (! preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            throw new InvalidArgumentException("Not a valid hex colour: {$hex}");
        }

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }
}
