{{--
    The live thermometer, for a projector.

    Its own document rather than the site layout: no header, no footer, no
    cookie banner, no announcement bar — a hall full of people is looking at
    a number. Dark by default (`.dark` on <html>) because that is what a
    projector in a dim room wants; `?theme=light` for a bright screen.

    Everything on it is the appeal's own CMS content or the settings; the
    numbers come in with the page and are refreshed by resources/js/screen.js
    from the feed URL in `data-screen`. Without script the page still shows
    the figures it was served with, which is what a projector would show for
    the next five seconds anyway.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="{{ $theme === 'dark' ? 'dark' : '' }}" data-theme="{{ $theme }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style nonce="{{ $cspNonce ?? '' }}">{{ app(App\Support\ThemeTokens::class)->css() }}</style>
    <x-site.meta :meta="$meta" />
    <meta name="theme-color" content="{{ app(App\Support\ThemeTokens::class)->value('bg', $theme) }}">
    <link rel="preload" href="{{ asset('fonts/Inter-latin.woff2') }}" as="font" type="font/woff2" crossorigin>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body
    class="min-h-screen overflow-x-hidden bg-[var(--bg)] text-[var(--text-primary)] antialiased"
    data-screen
    data-screen-feed="{{ $feedUrl }}"
    data-screen-poll="{{ $pollMs }}"
>
    <main class="mx-auto flex min-h-screen max-w-7xl flex-col justify-between gap-8 px-6 py-8 lg:px-12 lg:py-10" aria-live="polite">
        <header class="flex items-start justify-between gap-6">
            <div>
                <p class="text-lg font-semibold uppercase tracking-[0.2em] text-[var(--text-muted)] lg:text-xl">
                    {{ setting('general.short_name', config('app.name')) }}
                </p>
                <h1 class="mt-2 text-3xl font-bold leading-tight tracking-tight sm:text-5xl lg:text-6xl" data-screen-title>{{ $cause->title }}</h1>
            </div>

            @if ($cause->featuredImage)
                <x-media.image
                    :media="$cause->featuredImage"
                    size="thumb"
                    :eager="true"
                    class="hidden h-24 w-24 shrink-0 rounded-lg object-cover lg:block lg:h-32 lg:w-32"
                />
            @endif
        </header>

        <section class="grid gap-10 lg:grid-cols-[1fr_auto] lg:items-end">
            <div>
                <p class="text-xl text-[var(--text-muted)] lg:text-2xl">{{ __('Raised so far') }}</p>
                <p class="mt-2 text-6xl font-bold tabular-nums leading-none tracking-tight text-[var(--brand-primary)] sm:text-8xl lg:text-[9rem]" data-screen-raised>{{ $feed['raised']['formatted'] }}</p>

                @if ($feed['goal'])
                    <div class="mt-8">
                        <div class="h-8 w-full overflow-hidden rounded-full bg-[var(--surface)] lg:h-12" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ min(100, $feed['percent'] ?? 0) }}" aria-label="{{ __('Progress towards the goal') }}" data-screen-bar>
                            <div class="h-full rounded-full bg-[var(--brand-secondary)] transition-[width] duration-700 ease-out" style="width: {{ min(100, $feed['percent'] ?? 0) }}%" data-screen-fill></div>
                        </div>
                        <p class="mt-4 flex flex-wrap items-baseline gap-x-6 gap-y-1 text-2xl text-[var(--text-muted)] lg:text-4xl">
                            <span><strong class="font-semibold text-[var(--text-primary)]" data-screen-percent>{{ $feed['percent'] ?? 0 }}%</strong> {{ __('of') }} <span data-screen-goal>{{ $feed['goal']['formatted'] }}</span></span>
                            <span><strong class="font-semibold text-[var(--text-primary)]" data-screen-count>{{ number_format($feed['count']) }}</strong> {{ __('gifts') }}</span>
                        </p>
                    </div>
                @else
                    <p class="mt-6 text-2xl text-[var(--text-muted)] lg:text-4xl"><strong class="font-semibold text-[var(--text-primary)]" data-screen-count>{{ number_format($feed['count']) }}</strong> {{ __('gifts') }}</p>
                @endif
            </div>

            <div class="flex items-center gap-6 lg:flex-col lg:items-end lg:text-right">
                <div class="w-32 shrink-0 rounded-lg bg-white p-2 sm:w-40 lg:w-56 [&>svg]:h-auto [&>svg]:w-full" aria-hidden="true">{!! $qr !!}</div>
                <p class="text-lg leading-snug text-[var(--text-muted)] lg:text-2xl">
                    {{ __('Scan to give') }}<br>
                    <span class="break-all font-semibold text-[var(--text-primary)]">{{ preg_replace('#^https?://#', '', route('donate', ['cause' => $cause->slug])) }}</span>
                </p>
            </div>
        </section>

        <section aria-labelledby="screen-recent" class="min-h-[6rem]">
            <h2 id="screen-recent" class="sr-only">{{ __('Recent gifts') }}</h2>
            <ul class="flex flex-wrap gap-3 lg:gap-4" data-screen-recent>
                @foreach ($feed['recent'] as $gift)
                    <li class="rounded-full border border-[var(--border)] bg-[var(--surface)] px-5 py-2 text-xl lg:px-7 lg:py-3 lg:text-3xl">
                        <span class="font-semibold">{{ $gift['name'] }}</span>
                        @if ($gift['at'])
                            <span class="text-[var(--text-muted)]">· {{ $gift['at'] }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
            <template data-screen-gift>
                <li class="rounded-full border border-[var(--border)] bg-[var(--surface)] px-5 py-2 text-xl lg:px-7 lg:py-3 lg:text-3xl">
                    <span class="font-semibold" data-name></span>
                    <span class="text-[var(--text-muted)]" data-at></span>
                </li>
            </template>
        </section>
    </main>
</body>
</html>
