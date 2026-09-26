<?php

declare(strict_types=1);

namespace App\Blocks;

/**
 * The presentation choices every block shares, and the CSS they turn into.
 *
 * ── A closed vocabulary, deliberately ───────────────────────────────────────
 *
 * Every option here is a fixed list. There is no free-text colour, no arbitrary
 * pixel value, no custom class field. Blueprint §3.2 rejected the "unlimited
 * freeform blocks" model for the same reason this rejects freeform styling: it
 * is how a CMS becomes off-brand and unmaintainable, one well-meaning edit at a
 * time.
 *
 * The practical consequence is worth stating, because it is the whole argument.
 * A colour picker on a section produces pages that fail WCAG contrast the
 * moment somebody chooses a light green. A padding field in pixels produces a
 * site with fourteen different spacings. These options resolve to theme tokens
 * and to a spacing scale, so every combination an editor can reach is one the
 * designer already approved — and a change to the palette moves all of them at
 * once.
 *
 * ── Nothing here can inject anything ────────────────────────────────────────
 *
 * `classesFor()` never interpolates a stored value into markup. It LOOKS UP the
 * stored value in a map and returns the class from the map, so a `settings`
 * column edited by hand — or by a SQL injection somewhere else entirely — can
 * only ever produce a class this file already contains, or the default.
 */
final class SectionSettings
{
    /**
     * Background, as theme tokens rather than colours.
     *
     * `brand` and `brand-secondary` carry their own foreground token, because a
     * band whose background changed and whose text did not is the commonest way
     * a themed page becomes unreadable.
     *
     * @var array<string, string>
     */
    public const BACKGROUNDS = [
        'none' => '',
        'subtle' => 'bg-[var(--surface)]',
        'sunken' => 'bg-[var(--surface-sunken)]',
        'brand' => 'bg-[var(--brand-primary)] text-[var(--text-on-brand)]',
        'brand-secondary' => 'bg-[var(--brand-secondary)] text-[var(--text-on-secondary)]',
        'inverse' => 'bg-[var(--surface-inverse)] text-[var(--text-on-brand)]',
    ];

    /**
     * Vertical rhythm, on a scale rather than in pixels.
     *
     * Each step is smaller on a phone than on a laptop. A single fixed padding
     * that looks generous on a desktop is a third of a phone screen spent on
     * nothing.
     *
     * @var array<string, string>
     */
    public const PADDING = [
        'none' => '',
        'small' => 'py-6 sm:py-8',
        'medium' => 'py-10 sm:py-16',
        'large' => 'py-16 sm:py-24',
    ];

    /** @var array<string, string> */
    public const WIDTHS = [
        'narrow' => 'max-w-3xl',
        'default' => 'max-w-6xl',
        'wide' => 'max-w-7xl',
        // Edge to edge. The inner padding still applies, so text never touches
        // the side of a phone screen.
        'full' => 'max-w-none',
    ];

    /** @var array<string, string> */
    public const ALIGNMENTS = [
        'left' => 'text-left',
        'centre' => 'text-center',
    ];

    /**
     * Which screen sizes a section appears on.
     *
     * ⚠ `display: none`, NOT a separate render. Both versions are in the HTML,
     * so this hides a section from sighted users at one breakpoint and hides it
     * from nobody in a screen reader — which is why it is described as
     * "emphasis", not as a way to serve different content. A block genuinely
     * meant for one audience should be a different page, not a hidden one.
     *
     * @var array<string, string>
     */
    public const VISIBILITY = [
        'all' => '',
        'desktop' => 'hidden md:block',
        'mobile' => 'md:hidden',
    ];

    /** @var array<string, mixed> */
    private array $settings;

    /** @param array<string, mixed>|null $settings */
    public function __construct(?array $settings = null)
    {
        $this->settings = $settings ?? [];
    }

    /** The defaults, which are also what an unstyled section resolves to. */
    public static function defaults(): array
    {
        return [
            'background' => 'none',
            'padding' => 'medium',
            'width' => 'default',
            'alignment' => 'left',
            'visibility' => 'all',
            'dark_variant' => false,
        ];
    }

    /** The classes for the outer `<section>`. */
    /**
     * @param  bool  $withPadding  false for a band that sets its own height.
     *                             The hero is the one: its picture fills the
     *                             whole band, so the generic vertical padding
     *                             would show as a strip of page background
     *                             above and below the photograph.
     */
    public function sectionClasses(bool $withPadding = true): string
    {
        return trim(implode(' ', array_filter([
            $this->lookup(self::BACKGROUNDS, 'background', 'none'),
            $withPadding ? $this->lookup(self::PADDING, 'padding', 'medium') : '',
            $this->lookup(self::VISIBILITY, 'visibility', 'all'),
            /*
             * A section that renders in its dark palette whatever the page is
             * doing. Useful for one band on an otherwise light page — and
             * implemented by putting `.dark` on the section, so the same tokens
             * the rest of the site uses resolve to their dark values inside it
             * rather than a second set of colours existing anywhere.
             */
            $this->boolean('dark_variant') ? 'dark bg-[var(--bg)] text-[var(--text)]' : '',
        ])));
    }

    /** The classes for the container inside it. */
    public function containerClasses(): string
    {
        return trim(implode(' ', array_filter([
            'mx-auto px-4',
            $this->lookup(self::WIDTHS, 'width', 'default'),
            $this->lookup(self::ALIGNMENTS, 'alignment', 'left'),
        ])));
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? self::defaults()[$key] ?? $default;
    }

    /**
     * A stored value, resolved through a map it cannot escape.
     *
     * The lookup is the security property: whatever is in the column, the
     * output is a value from the constant above or the default. Nothing stored
     * reaches the class attribute.
     *
     * @param  array<string, string>  $map
     */
    private function lookup(array $map, string $key, string $fallback): string
    {
        $value = $this->settings[$key] ?? self::defaults()[$key] ?? $fallback;

        return is_string($value) && array_key_exists($value, $map)
            ? $map[$value]
            : ($map[$fallback] ?? '');
    }

    private function boolean(string $key): bool
    {
        return (bool) ($this->settings[$key] ?? self::defaults()[$key] ?? false);
    }

    /**
     * The choices, as label => value pairs for the admin form.
     *
     * Generated from the same constants the renderer reads, so a background
     * offered in the panel is by construction a background the renderer knows
     * how to draw.
     *
     * @return array<string, array<string, string>>
     */
    public static function options(): array
    {
        return [
            'background' => [
                'none' => 'None',
                'subtle' => 'Subtle grey',
                'sunken' => 'Sunken grey',
                'brand' => 'Brand green',
                'brand-secondary' => 'Brand orange',
                'inverse' => 'Inverse',
            ],
            'padding' => [
                'none' => 'None',
                'small' => 'Small',
                'medium' => 'Medium',
                'large' => 'Large',
            ],
            'width' => [
                'narrow' => 'Narrow — for reading',
                'default' => 'Default',
                'wide' => 'Wide',
                'full' => 'Full width',
            ],
            'alignment' => [
                'left' => 'Left',
                'centre' => 'Centred',
            ],
            'visibility' => [
                'all' => 'Everywhere',
                'desktop' => 'Large screens only',
                'mobile' => 'Phones only',
            ],
        ];
    }
}
