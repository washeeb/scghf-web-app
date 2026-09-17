{{--
    The Cloudflare Turnstile widget, drawn only when both keys are set.

    The "managed" mode shows nothing to most people and a checkbox to the
    few it is unsure about; nobody transcribes traffic lights. The theme
    follows the site's own, so a dark page does not get a white box. With
    the script blocked or JavaScript off, the field is absent and the server
    says so in words rather than failing silently.
--}}
@if (App\Support\Turnstile::enabled())
    @php
        $theme = app(App\Support\ThemePreference::class)->resolve(request());
        $theme = in_array($theme, ['light', 'dark'], true) ? $theme : 'auto';
    @endphp

    <div
        class="cf-turnstile"
        data-sitekey="{{ App\Support\Turnstile::siteKey() }}"
        data-theme="{{ $theme }}"
        data-size="flexible"
        data-appearance="interaction-only"
        data-action="donate"
    ></div>

    <noscript>
        <p class="text-sm text-[var(--danger)]">{{ __('The security check needs JavaScript. If you cannot turn it on, please give by Mobile Money or bank transfer instead.') }}</p>
    </noscript>

    @error(App\Support\Turnstile::FIELD)
        <p role="alert" class="text-sm text-[var(--danger)]">{{ $message }}</p>
    @enderror

    @once
        @push('scripts')
            <script nonce="{{ $cspNonce ?? '' }}" src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
        @endpush
    @endonce
@endif
