{{--
    The theme control: light, dark, the device's choice, and the third
    palette ("Vibrant", or whatever Settings → Site names it).

    A `<select>` rather than a cycling button, because three states cannot be
    represented honestly by one button: whatever icon it shows, the user cannot
    tell whether "dark" means "it is dark now" or "click for dark", and there is
    no way to show that "system" is selected at all.

    A select announces its current value, is operable by keyboard and touch
    without any JavaScript from us, and needs no ARIA to explain itself.
--}}
<label class="relative">
    <span class="sr-only">{{ __('Colour theme') }}</span>
    <select
        data-theme-toggle
        class="rounded-md border border-[var(--border)] bg-[var(--surface)] px-2 py-1.5 text-sm text-[var(--text-primary)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus-ring)]"
    >
        <option value="system">{{ __('System') }}</option>
        <option value="light">{{ __('Light') }}</option>
        <option value="dark">{{ __('Dark') }}</option>
        <option value="vibrant">{{ setting('site.vibrant_theme_label', __('Vibrant')) }}</option>
    </select>
</label>
