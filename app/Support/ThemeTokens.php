<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ThemeSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * Turns the theme tokens the trustees approved into CSS custom properties.
 *
 * ── Why these are inlined rather than compiled into the stylesheet ──────────
 *
 * The palette is CMS content. It was sampled from the foundation's own logo
 * pack, it is editable in the admin panel, and CLAUDE.md's rule says colours do
 * not get hardcoded. Compiling them into `app.css` at build time would mean
 * changing the brand colour required a deploy — which is exactly the situation
 * the rule exists to prevent, and the same reason the Filament brand name is
 * resolved through a closure.
 *
 * So the tokens are emitted as a `<style>` block in the head. That costs bytes
 * on every page rather than being cached with the stylesheet, which is a real
 * cost on a 3G connection — so the block is small (about forty declarations,
 * under 2KB before compression), cached in memory, and sits before the
 * stylesheet link so it never blocks it.
 *
 * ── Both themes are always emitted ──────────────────────────────────────────
 *
 * Light on `:root`, dark under `.dark`. Not one or the other chosen server-side:
 * a visitor whose system flips to dark mode, or who uses the toggle, must get
 * the new palette without a round trip. Sending both is a few hundred bytes and
 * removes an entire class of "the page went half-dark" bug.
 */
class ThemeTokens
{
    private const CACHE_KEY = 'theme.tokens.css';

    /**
     * Fallback palette, used only when the database cannot be read.
     *
     * ⚠ These are NOT the brand colours and are not meant to be.
     *
     * They exist so that a page still renders legibly during a migration, a
     * database blip, or before the seeder has run — a site that renders black
     * text on a black background because a query failed is worse than one that
     * renders in plain greys for a minute. The real values live in
     * `theme_settings`, seeded from the logo pack.
     *
     * ⚠ The NAMES here must match the seeded ones exactly.
     *
     * They did not. This list called them `text` and `focus` while the seeder
     * called them `text-primary` and `focus-ring`, and every view referenced
     * the fallback's names — so the site was correct only when the palette
     * could NOT be read. With a seeded palette, `color: var(--text)` resolved
     * to nothing, the declaration fell back to the initial black, and the dark
     * theme rendered black text on a dark background.
     *
     * It stayed invisible for exactly the reason it is dangerous: tests that
     * do not seed the palette hit this fallback, where the names matched.
     * `ThemeTokenCoverageTest` now compares the two lists and the views against
     * each other, so a token can no longer be referenced by a name nothing
     * defines.
     *
     * @var array<string, array{string, string}> token => [light, dark]
     */
    private const FALLBACK = [
        'bg' => ['#ffffff', '#0b0b0d'],
        'surface' => ['#f6f6f7', '#141417'],
        'text-primary' => ['#18181b', '#f4f4f5'],
        'text-muted' => ['#52525b', '#a1a1aa'],
        'border' => ['#e4e4e7', '#27272a'],
        'brand-primary' => ['#0b4d3f', '#2ec4a8'],
        'text-on-brand' => ['#ffffff', '#04241e'],
        'brand-secondary' => ['#fc6302', '#ff9c5c'],
        'text-on-secondary' => ['#3a1200', '#3a1200'],
        'focus-ring' => ['#0b4d3f', '#2ec4a8'],
    ];

    /**
     * The `<style>` contents: light on `:root`, dark under `.dark`.
     *
     * Cached because it is read on every page render and changes about as often
     * as the logo. Cleared by `ThemeSetting`'s own save hook, so an editor sees
     * their change immediately rather than whenever a TTL happens to expire.
     */
    /**
     * One token's value for one theme, for the places that cannot use CSS —
     * an email, a PDF. Falls back to the built-in palette.
     */
    public function value(string $token, string $theme = 'light'): string
    {
        $tokens = $this->tokens();

        return $tokens[$token][$theme] ?? self::FALLBACK[$token][$theme === 'dark' ? 1 : 0] ?? '#000000';
    }

    public function css(): HtmlString
    {
        $css = Cache::rememberForever(self::CACHE_KEY, fn (): string => $this->build());

        return new HtmlString($css);
    }

    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
        self::$loaded = null;
    }

    /**
     * The rows, once per process. `value()` is asked several times in one
     * request (the manifest asks for two colours and every icon URL hashes
     * one), and `app(ThemeTokens::class)` is a fresh instance each time, so
     * the memo is static. Cleared with the CSS by `flush()`.
     *
     * @var array<string, array<string, string>>|null
     */
    private static ?array $loaded = null;

    private function build(): string
    {
        $tokens = $this->tokens();

        $light = $this->declarations($tokens, 'light');
        $dark = $this->declarations($tokens, 'dark');

        /*
         * `color-scheme` is not decoration. It tells the browser to render its
         * own furniture — scrollbars, form controls, the address bar on mobile
         * — to match. Without it a dark page keeps white scrollbars and a
         * blindingly light autofill dropdown, which looks broken rather than
         * themed.
         */
        return ':root{color-scheme:light;'.$light.'}'
            .'.dark{color-scheme:dark;'.$dark.'}';
    }

    /**
     * @param  array<string, array<string, string>>  $tokens
     */
    private function declarations(array $tokens, string $theme): string
    {
        $out = '';

        foreach ($tokens as $token => $values) {
            $value = $values[$theme] ?? null;

            if ($value === null) {
                continue;
            }

            $out .= '--'.$token.':'.$value.';';
        }

        return $out;
    }

    /**
     * Every token, keyed by name, with its light and dark values.
     *
     * @return array<string, array<string, string>>
     */
    private function tokens(): array
    {
        return self::$loaded ??= $this->load();
    }

    /** @return array<string, array<string, string>> */
    private function load(): array
    {
        try {
            $rows = ThemeSetting::query()
                ->select(['theme', 'token', 'value'])
                ->orderBy('token')
                ->get();

            if ($rows->isEmpty()) {
                return $this->fallbackTokens();
            }

            $tokens = [];

            foreach ($rows as $row) {
                $name = $this->sanitiseToken((string) $row->token);
                $value = $this->sanitiseValue((string) $row->value);

                if ($name === null || $value === null) {
                    continue;
                }

                $tokens[$name][(string) $row->theme] = $value;
            }

            return $tokens === [] ? $this->fallbackTokens() : $tokens;
        } catch (Throwable) {
            /*
             * The table may not exist yet — this runs during `migrate:fresh`,
             * and an exception here would make the database unmigratable. The
             * fallback keeps a page legible rather than correct.
             */
            return $this->fallbackTokens();
        }
    }

    /** @return array<string, array<string, string>> */
    private function fallbackTokens(): array
    {
        $tokens = [];

        foreach (self::FALLBACK as $name => [$light, $dark]) {
            $tokens[$name] = ['light' => $light, 'dark' => $dark];
        }

        return $tokens;
    }

    /**
     * A token name safe to put in a CSS custom property.
     *
     * These come from a database column an administrator can edit, and the
     * output goes into an inline `<style>` block — so a token named
     * `x}</style><script>` would be a stored XSS with the site's own CSP
     * blessing. Restricting to the characters a CSS identifier may contain
     * makes that impossible rather than unlikely.
     */
    private function sanitiseToken(string $token): ?string
    {
        $token = trim($token);

        return preg_match('/^[a-z0-9-]{1,64}$/i', $token) === 1 ? $token : null;
    }

    /**
     * A value safe to put in a CSS declaration.
     *
     * Same reasoning as the token name, and the same attack. Deliberately
     * permissive enough for the things a design token legitimately holds — hex
     * colours, `rgb()`, `oklch()`, lengths, font stacks — and closed to the
     * characters that end a declaration or a block.
     */
    private function sanitiseValue(string $value): ?string
    {
        $value = trim($value);

        if ($value === '' || mb_strlen($value) > 191) {
            return null;
        }

        // `;` `{` `}` `<` `>` `\` and `@` all let a value escape its
        // declaration. `url(` is excluded outright: a token has no business
        // fetching anything, and it is the usual way CSS exfiltrates data.
        if (preg_match('/[;{}<>\\\\@]/', $value) === 1) {
            return null;
        }

        return stripos($value, 'url(') === false ? $value : null;
    }
}
