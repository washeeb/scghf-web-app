{{--
    The theme control: light, dark, the device's choice, and the third
    palette ("Vibrant", or whatever Settings → Site names it).

    ── An icon button that opens a menu ────────────────────────────────────────

    The button shows the icon of the theme in force — sun, moon, a screen for
    "match the device", a swatch for the third palette — and opens a short
    menu with the four names, the current one ticked. Four named choices
    cannot be a cycling button (nobody can tell whether the moon means "it is
    dark" or "click for dark"), and a bare <select> looked like a form field
    in a row of navigation.

    It is a <details> disclosure like every other menu in the header: opens on
    click and Enter, announces its own expanded state, closes on Escape and
    click-away (navigation.js), and works without our script. The choices are
    `menuitemradio` buttons so a screen reader hears "Dark, radio button,
    checked" rather than four unrelated buttons. theme.js applies the choice,
    stores it, moves the tick and closes the menu.

    Which icon shows is decided by CSS from the `data-theme` attribute the
    server puts on <html> (and theme.js updates), so there is no flash of the
    wrong icon and no script needed to draw the right one.
--}}
@php
    $current = app(App\Support\ThemePreference::class)->resolve(request());
    // Every name from Settings → Header (the third palette's from Site).
    $themes = [
        'light' => ['label' => setting('header.theme_light_label', __('Light')), 'icon' => 'sun'],
        'dark' => ['label' => setting('header.theme_dark_label', __('Dark')), 'icon' => 'moon'],
        'system' => ['label' => setting('header.theme_system_label', __('Match my device')), 'icon' => 'computer-desktop'],
        'vibrant' => ['label' => setting('site.vibrant_theme_label', __('Vibrant')), 'icon' => 'swatch'],
    ];
    $themeLabel = setting('header.theme_label', __('Colour theme'));
@endphp

<details data-nav-dropdown data-no-hover data-theme-menu class="group relative">
    <summary
        class="flex size-10 cursor-pointer list-none items-center justify-center rounded-full text-[var(--text-primary)] hover:bg-[var(--surface-sunken)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus-ring)]"
        aria-label="{{ $themeLabel }}"
        title="{{ $themeLabel }}"
    >
        @foreach ($themes as $key => $theme)
            <span data-theme-icon="{{ $key }}" class="theme-icon">
                <x-ui.icon :name="$theme['icon']" class="size-5" />
            </span>
        @endforeach
    </summary>

    <ul
        role="menu"
        aria-label="{{ $themeLabel }}"
        class="absolute right-0 top-full z-40 mt-1 min-w-56 whitespace-nowrap rounded-[var(--radius-md)] border border-[var(--border)] bg-[var(--bg)] p-1 shadow-[var(--shadow-lg)]"
    >
        @foreach ($themes as $key => $theme)
            <li role="none">
                <button
                    type="button"
                    role="menuitemradio"
                    aria-checked="{{ $current === $key ? 'true' : 'false' }}"
                    data-theme-option="{{ $key }}"
                    class="flex w-full items-center gap-3 rounded px-3 py-2 text-left text-sm font-medium text-[var(--text-primary)] hover:bg-[var(--surface-sunken)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus-ring)] aria-checked:text-[var(--brand-primary)]"
                >
                    <x-ui.icon :name="$theme['icon']" class="size-4 shrink-0" />
                    <span class="flex-1">{{ $theme['label'] }}</span>
                    {{-- The tick: drawn only for the checked item, by CSS. --}}
                    <x-ui.icon name="check" class="theme-check size-4 shrink-0" />
                </button>
            </li>
        @endforeach
    </ul>
</details>
