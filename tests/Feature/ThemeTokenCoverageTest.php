<?php

declare(strict_types=1);

use App\Support\ThemeTokens;
use Database\Seeders\ThemeSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Every colour a view asks for has to exist
|--------------------------------------------------------------------------
|
| ⚠ THE BUG THIS FILE EXISTS FOR.
|
| The views referenced `var(--text)` and `var(--focus)` in 114 places. The
| seeder called those tokens `text-primary` and `focus-ring`. Nothing connected
| the two, so with a real palette in the database `color: var(--text)` resolved
| to nothing at all, the declaration fell back to the initial colour, and the
| DARK THEME RENDERED BLACK TEXT ON A DARK BACKGROUND — on every page of the
| public site.
|
| It survived because of where the names DID match: `ThemeTokens::FALLBACK`,
| the emergency palette used when `theme_settings` cannot be read. Tests that
| do not seed the palette take that path, so the whole suite was green while
| the product was broken. That is the shape of bug worth writing a test about
| — not the one that fails loudly, but the one whose safety net was the thing
| hiding it.
|
| An undefined custom property is silent. No console error, no build failure,
| no missing file. The only thing that catches it is a check like this one.
|
*/

/**
 * Every `var(--token)` the Blade views reference.
 *
 * @return array<int, string>
 */
function tokensUsedInViews(): array
{
    $used = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(resource_path('views'))
    );

    foreach ($files as $file) {
        if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        preg_match_all('/var\(--([a-z0-9-]+)\)/', (string) file_get_contents($file->getPathname()), $matches);

        $used = [...$used, ...$matches[1]];
    }

    return array_values(array_unique($used));
}

it('defines every colour the views ask for', function () {
    $this->seed(ThemeSettingsSeeder::class);
    ThemeTokens::flush();

    $css = (string) app(ThemeTokens::class)->css();

    $missing = collect(tokensUsedInViews())
        ->reject(fn (string $token): bool => str_contains($css, '--'.$token.':'))
        ->values();

    expect($missing)->toBeEmpty(
        'These tokens are used in a view and defined by nothing, so the property silently '
        .'resolves to nothing: '.$missing->implode(', ')
    );
});

it('uses the same token names in the fallback palette as in the seeded one', function () {
    /*
     * The fallback is what renders when the palette cannot be read. If its
     * names drift from the seeded ones, the site is correct in exactly the
     * situation where something has already gone wrong, and broken the rest of
     * the time — which is how the original bug hid for two phases.
     */
    ThemeTokens::flush();
    $fallbackCss = (string) app(ThemeTokens::class)->css();

    $this->seed(ThemeSettingsSeeder::class);
    ThemeTokens::flush();
    $seededCss = (string) app(ThemeTokens::class)->css();

    preg_match_all('/--([a-z0-9-]+):/', $fallbackCss, $fallback);

    $notInSeeded = collect($fallback[1])
        ->unique()
        ->reject(fn (string $token): bool => str_contains($seededCss, '--'.$token.':'))
        ->values();

    expect($notInSeeded)->toBeEmpty(
        'The fallback palette defines tokens the real palette does not, so a view using one '
        .'works only while the database is unreadable: '.$notInSeeded->implode(', ')
    );
});
