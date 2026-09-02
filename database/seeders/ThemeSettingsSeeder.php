<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ThemeSetting;
use Illuminate\Database\Seeder;

/**
 * The palette from PHASE-1-BLUEPRINT.md §5, seeded with its contrast
 * obligations attached.
 *
 * Every colour was sampled from the uploaded logo pack or derived from those
 * samples, and every pair here was computed during Phase 1. Attaching
 * `contrast_against` + `min_contrast` to each token is what turns that one-off
 * check into something the test suite re-verifies on every run and the admin
 * editor enforces on every save.
 *
 * Brand colours, sampled from the logo PNGs:
 *   #0B4D3F  deep green   — the wordmark
 *   #FC6302  orange       — the wordmark
 *   #2EC4A8  teal         — gradient end of the green figure
 *   #0068EC  blue         — the icon-only mark, now the Every Soul Missions accent
 *
 * Idempotent — safe on every deploy.
 */
class ThemeSettingsSeeder extends Seeder
{
    /**
     * [token, category, light value, dark value, label, contrast_against, min_contrast]
     *
     * A null contrast target means the token carries no legibility obligation:
     * a page background is not "readable against" anything, and spacing has no
     * colour at all.
     *
     * @var array<int, array{0:string,1:string,2:string,3:string,4:string,5:?string,6:?float}>
     */
    private const TOKENS = [
        // ── Surfaces ─────────────────────────────────────────────────────────
        ['bg',              'colour', '#FFFFFF', '#071310', 'Page background',        null, null],
        ['bg-subtle',       'colour', '#F7F9F8', '#0B1A16', 'Subtle background',      null, null],
        ['surface',         'colour', '#FFFFFF', '#122420', 'Card surface',           null, null],
        ['surface-sunken',  'colour', '#F1F5F4', '#0B1A16', 'Sunken surface',         null, null],
        ['surface-raised',  'colour', '#FFFFFF', '#18302A', 'Raised surface',         null, null],
        ['surface-inverse', 'colour', '#0B4D3F', '#ECF5F2', 'Inverse surface',        null, null],

        // ── Text ─────────────────────────────────────────────────────────────
        // 17.79:1 light / 17.04:1 dark
        ['text-primary',    'colour', '#0F1A17', '#ECF5F2', 'Primary text',           'bg', 4.5],
        // 7.99:1 / 10.08:1
        ['text-secondary',  'colour', '#44544F', '#ACC2BB', 'Secondary text',         'bg', 4.5],
        // 5.24:1 / 7.03:1 — the tightest of the three, and the one most at risk
        // if anyone lightens it "just a little".
        ['text-muted',      'colour', '#5E706B', '#8AA39C', 'Muted text',             'bg', 4.5],

        // ── Borders ──────────────────────────────────────────────────────────
        // Decorative dividers carry no obligation; they are not UI boundaries.
        ['border',          'colour', '#E2E8E5', '#24403A', 'Divider',                null, null],
        ['border-strong',   'colour', '#CBD5D1', '#33544C', 'Strong divider',         null, null],
        // Input and control boundaries DO — SC 1.4.11. 3.77:1 / 3.70:1
        ['border-interactive', 'colour', '#7A8683', '#5A736C', 'Control border',      'bg', 3.0],

        // ── Brand ────────────────────────────────────────────────────────────
        // Deep green is the light-theme brand; in dark mode it is the background
        // family, so teal takes the interactive role. 9.78:1 / 8.63:1
        ['brand-primary',   'colour', '#0B4D3F', '#2EC4A8', 'Brand primary',          'bg', 4.5],
        ['brand-primary-hover', 'colour', '#073A30', '#5FE0B8', 'Brand primary hover', 'bg', 4.5],
        // Text placed ON the brand fill. 9.78:1 / 7.50:1
        ['text-on-brand',   'colour', '#FFFFFF', '#04241E', 'Text on brand',          'brand-primary', 4.5],

        // Orange as a FILL. White on it is only 3.03:1 and is banned (§5.5) —
        // hence a separate ink token below rather than assuming white.
        ['brand-secondary', 'colour', '#FC6302', '#FF9C5C', 'Brand secondary',        null, null],
        // Orange as TEXT. 5.63:1 / 9.16:1
        ['brand-secondary-ink', 'colour', '#B83E00', '#FF9C5C', 'Brand secondary text', 'bg', 4.5],
        // Ink ON the orange fill — the donate button. 5.47:1 / 8.02:1
        ['text-on-secondary', 'colour', '#3A1200', '#3A1200', 'Text on secondary',    'brand-secondary', 4.5],

        ['accent-teal',     'colour', '#2EC4A8', '#2EC4A8', 'Teal accent',            null, null],

        // ── Semantic ─────────────────────────────────────────────────────────
        ['success',         'colour', '#15803D', '#4ADE80', 'Success',                'bg', 4.5],
        ['warning',         'colour', '#8A5300', '#FBBF24', 'Warning',                'bg', 4.5],
        ['danger',          'colour', '#C81E1E', '#FF7B7B', 'Danger',                 'bg', 4.5],
        ['info',            'colour', '#0059C9', '#63B3FF', 'Info',                   'bg', 4.5],

        // ── Division accents — Blueprint §5.4 ────────────────────────────────
        ['division-lifespring', 'colour', '#0F766E', '#2EC4A8', 'Life Spring (Health)',       'bg', 4.5],
        ['division-brightpath', 'colour', '#B83E00', '#FF9C5C', 'BrightPath (Education)',     'bg', 4.5],
        ['division-legacy',     'colour', '#0B4D3F', '#5FE0B8', 'Legacy of Love (Welfare)',   'bg', 4.5],
        ['division-everysoul',  'colour', '#0059C9', '#63B3FF', 'Every Soul (Evangelism)',    'bg', 4.5],

        // ── Focus ────────────────────────────────────────────────────────────
        // 4.45:1 / 8.63:1 — non-text, needs 3.0
        ['focus-ring',      'colour', '#0B7D66', '#2EC4A8', 'Focus ring',             'bg', 3.0],

        // ── Typography ───────────────────────────────────────────────────────
        ['font-heading', 'typography', '"Plus Jakarta Sans", system-ui, sans-serif', '"Plus Jakarta Sans", system-ui, sans-serif', 'Heading typeface', null, null],
        ['font-body',    'typography', 'Inter, system-ui, sans-serif', 'Inter, system-ui, sans-serif', 'Body typeface', null, null],
        ['font-size-base', 'typography', '16px', '16px', 'Base font size',            null, null],
        ['line-height-base', 'typography', '1.65', '1.65', 'Base line height',        null, null],

        // ── Radius — the logo is all soft curves, so the system leans generous
        ['radius-md',    'radius', '10px',   '10px',   'Radius — inputs',             null, null],
        ['radius-lg',    'radius', '14px',   '14px',   'Radius — cards',              null, null],
        ['radius-xl',    'radius', '20px',   '20px',   'Radius — feature cards',      null, null],
        ['radius-2xl',   'radius', '28px',   '28px',   'Radius — hero panels',        null, null],
        ['radius-full',  'radius', '9999px', '9999px', 'Radius — pill buttons',       null, null],

        // ── Spacing ──────────────────────────────────────────────────────────
        ['space-unit',      'spacing', '4px',    '4px',    'Spacing base unit',       null, null],
        ['container-max',   'spacing', '1280px', '1280px', 'Container max width',     null, null],
        ['tap-target-min',  'spacing', '44px',   '44px',   'Minimum tap target',      null, null],

        // ── Shadow — tinted with the brand green, not pure black, so it sits in
        //    the same temperature as the surfaces. Dark mode expresses elevation
        //    with surface lightness plus a border instead.
        ['shadow-sm', 'shadow', '0 1px 3px 0 rgb(7 19 16 / 0.08)',      '0 1px 2px rgb(0 0 0 / 0.4)', 'Shadow — small',  null, null],
        ['shadow-md', 'shadow', '0 4px 8px -2px rgb(7 19 16 / 0.10)',   '0 1px 2px rgb(0 0 0 / 0.4)', 'Shadow — medium', null, null],
        ['shadow-lg', 'shadow', '0 12px 20px -6px rgb(7 19 16 / 0.12)', '0 1px 2px rgb(0 0 0 / 0.4)', 'Shadow — large',  null, null],

        // ── Motion ───────────────────────────────────────────────────────────
        ['duration-base', 'motion', '200ms', '200ms', 'Transition duration',          null, null],
        ['easing-base',   'motion', 'cubic-bezier(0.4, 0, 0.2, 1)', 'cubic-bezier(0.4, 0, 0.2, 1)', 'Transition easing', null, null],
    ];

    public function run(): void
    {
        $order = 0;

        foreach (self::TOKENS as [$token, $category, $light, $dark, $label, $against, $minContrast]) {
            $order++;

            foreach (['light' => $light, 'dark' => $dark] as $theme => $value) {
                ThemeSetting::updateOrCreate(
                    ['theme' => $theme, 'token' => $token],
                    [
                        'category' => $category,
                        'value' => $value,
                        'label' => $label,
                        'contrast_against' => $against,
                        'min_contrast' => $minContrast,
                        // Colours are locked: the brand is not something to
                        // delete by accident. Spacing and motion are safe to edit.
                        'is_locked' => $category === 'colour',
                        'sort_order' => $order,
                    ],
                );
            }
        }

        $this->command?->info(sprintf(
            'Seeded %d theme tokens across 2 themes.',
            count(self::TOKENS),
        ));
    }
}
