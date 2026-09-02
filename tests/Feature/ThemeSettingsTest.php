<?php

declare(strict_types=1);

use App\Models\ThemeSetting;
use App\Support\ContrastChecker;
use Database\Seeders\ThemeSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(ThemeSettingsSeeder::class);
});

it('seeds every token in both themes', function () {
    $light = ThemeSetting::where('theme', 'light')->pluck('token')->sort()->values();
    $dark = ThemeSetting::where('theme', 'dark')->pluck('token')->sort()->values();

    expect($light)->not->toBeEmpty()
        // A token existing in one theme only is how dark mode ends up with an
        // unstyled component nobody notices until a donor reports it.
        ->and($dark->all())->toBe($light->all());
});

/*
 * THE test for this module.
 *
 * CLAUDE.md requires both themes to meet WCAG 2.2 AA, checked component by
 * component rather than by inverting colours and hoping. PHASE-1-BLUEPRINT.md
 * §5.5 did that check once, by hand, in a document.
 *
 * This makes it an executable invariant: every seeded token that declares what
 * it must be legible against is re-verified on every test run, in both themes.
 * A palette edit that breaks AA now fails CI instead of shipping.
 */
it('meets AA contrast for every token that declares an obligation', function () {
    $checker = app(ContrastChecker::class);
    $failures = [];

    foreach (ThemeSetting::withContrastObligation()->get() as $token) {
        $partner = $token->contrastPartner();

        expect($partner)->not->toBeNull(
            "Token {$token->theme}/{$token->token} points at '{$token->contrast_against}', which does not exist."
        );

        $ratio = $checker->ratioRounded($token->value, $partner->value);

        if ($ratio < $token->min_contrast) {
            $failures[] = sprintf(
                '%s/%s: %s on %s = %.2f:1, needs %.2f:1',
                $token->theme, $token->token, $token->value, $partner->value, $ratio, $token->min_contrast,
            );
        }
    }

    expect($failures)->toBe([], "WCAG AA failures:\n".implode("\n", $failures));
});

it('exposes the ratio and pass state per token', function () {
    $token = ThemeSetting::where('theme', 'light')->where('token', 'text-primary')->first();

    expect($token->contrastRatio())->toBe(17.79)
        ->and($token->meetsContrast())->toBeTrue();
});

it('treats a token with no obligation as passing', function () {
    $bg = ThemeSetting::where('theme', 'light')->where('token', 'bg')->first();

    // A page background is not "readable against" anything.
    expect($bg->contrastRatio())->toBeNull()
        ->and($bg->meetsContrast())->toBeTrue();
});

it('detects a palette edit that breaks AA', function () {
    $token = ThemeSetting::where('theme', 'light')->where('token', 'text-muted')->first();

    // A plausible-looking "just a bit lighter" edit by an admin.
    $token->update(['value' => '#A8B4B0']);

    expect($token->fresh()->meetsContrast())->toBeFalse();
});

it('carries the brand colours sampled from the logo pack', function () {
    $light = ThemeSetting::where('theme', 'light')->pluck('value', 'token');

    // Sampled from the logo PNGs in Phase 1, not invented.
    expect($light['brand-primary'])->toBe('#0B4D3F')
        ->and($light['brand-secondary'])->toBe('#FC6302')
        ->and($light['accent-teal'])->toBe('#2EC4A8')
        // The icon-only mark's blue, reassigned as the Every Soul accent.
        ->and($light['division-everysoul'])->toBe('#0059C9');
});

it('does not put white text on the orange donate button', function () {
    // The specific trap from §5.5: white on #FC6302 is 3.03:1 and fails AA,
    // while looking perfectly reasonable to whoever writes it.
    $ink = ThemeSetting::where('theme', 'light')->where('token', 'text-on-secondary')->first();

    expect($ink->value)->not->toBe('#FFFFFF')
        ->and($ink->meetsContrast())->toBeTrue();
});

it('locks colour tokens against deletion but leaves spacing editable', function () {
    expect(ThemeSetting::where('token', 'brand-primary')->first()->is_locked)->toBeTrue()
        ->and(ThemeSetting::where('token', 'space-unit')->first()->is_locked)->toBeFalse();
});

it('is idempotent', function () {
    $before = ThemeSetting::count();
    $this->seed(ThemeSettingsSeeder::class);

    expect(ThemeSetting::count())->toBe($before);
});
