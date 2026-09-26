<?php

declare(strict_types=1);

namespace App\Blocks\Options;

/**
 * How tall the hero band stands.
 *
 * A closed list rather than a number, for the same reason the presentation
 * settings are: an editor typing `900` gets a hero nobody can see past on a
 * laptop, and the three that are here are the three the design supports.
 *
 * The classes are the padding above and below the text, per breakpoint. The
 * old hero was `pt-20 pb-28 sm:pt-28 sm:pb-36 lg:pt-32 lg:pb-40` — kept as
 * `tall`, because a page built before this existed must not change shape.
 * `compact` is the new default: roughly two-thirds of that, which on a laptop
 * leaves the first real section of the page visible without scrolling.
 */
final class HeroHeights
{
    public const DEFAULT = 'compact';

    /** @return array<string, string> */
    public static function options(): array
    {
        return [
            'compact' => __('Compact — the page below starts above the fold'),
            'standard' => __('Standard'),
            'tall' => __('Tall — fills most of the screen'),
        ];
    }

    /**
     * The vertical padding for one of the keys above.
     *
     * `$withControls` is a slider: the arrows, the dots and the pause button
     * stand at the foot of the band, and above them is the rounded panel the
     * next section rises into. Both need to be clear of the text, and the
     * controls need to be clear of the panel — a hero whose dots are half
     * behind that panel is the state this argument exists to prevent.
     */
    public static function classes(string $key, bool $withControls = false): string
    {
        if ($withControls) {
            return match ($key) {
                'standard' => 'pt-16 pb-36 sm:pt-20 sm:pb-40 lg:pt-24 lg:pb-44',
                'tall' => 'pt-20 pb-40 sm:pt-28 sm:pb-48 lg:pt-32 lg:pb-52',
                default => 'pt-12 pb-32 sm:pt-14 sm:pb-36 lg:pt-16 lg:pb-40',
            };
        }

        return match ($key) {
            'standard' => 'pt-16 pb-24 sm:pt-20 sm:pb-28 lg:pt-24 lg:pb-32',
            'tall' => 'pt-20 pb-28 sm:pt-28 sm:pb-36 lg:pt-32 lg:pb-40',
            default => 'pt-12 pb-20 sm:pt-14 sm:pb-24 lg:pt-16 lg:pb-28',
        };
    }

    /**
     * How far the control row sits above the foot of the band.
     *
     * More than the panel that rises into it (`h-8`, `sm:h-12` in the hero),
     * or the dots are drawn behind it.
     */
    public const CONTROL_OFFSET = 'pb-12 sm:pb-16';

    /**
     * A floor for the picture, so slides of differing text length do not make
     * the band jump as it rotates. Paired with the padding above.
     */
    public static function minHeight(string $key): string
    {
        return match ($key) {
            'standard' => 'min-h-[26rem] sm:min-h-[30rem]',
            'tall' => 'min-h-[32rem] sm:min-h-[38rem]',
            default => 'min-h-[22rem] sm:min-h-[26rem]',
        };
    }
}
