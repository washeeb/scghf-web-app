{{--
    "Sign in", or the account menu, in the header.

    ── Why this is a component and not a menu item ─────────────────────────────

    Everything else in the header comes from the `menus` tables, because it is
    content. This is not: it is the state of the current session, and it changes
    per visitor rather than per edit. The menu tables can already express
    "guests only" and "signed-in only" through `visible_to`, so the foundation
    CAN add its own account links there — this is the one that must exist
    whether they do or not.

    ── One control, a menu under it ────────────────────────────────────────────

    Signed in, the header shows one item — "Your account" — and the pages a
    donor comes back for sit under it, with Sign out last and set apart. Two
    separate top-level items ("Your account", "Sign out") spent header width
    on the rarest action and made it look like a link to a page.

    A <details> disclosure like the navigation dropdowns: click and Enter open
    it, Escape and click-away close it (navigation.js), and it works without
    script. In the phone panel (`list`) it is a plain list instead — a floating
    menu inside a sliding panel is two layers of the same thing.

    Signing out is a POST with a token. A GET sign-out link can be triggered by
    any page that embeds an image pointing at it, which is a small denial of
    service and a real annoyance.

    @param layout  `menu` (default) for the wide header, `list` for the phone panel
--}}
@props(['layout' => 'menu'])

@php
    $itemClass = 'flex w-full items-center gap-3 rounded px-3 py-2 text-sm font-medium text-[var(--text-primary)] hover:bg-[var(--surface-sunken)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus-ring)] aria-[current=page]:text-[var(--brand-primary)]';
    $links = auth()->check() ? collect([
        ['route' => 'account.dashboard', 'label' => __('Overview')],
        ['route' => 'account.impact', 'label' => __('Your impact')],
        ['route' => 'account.receipts', 'label' => __('Receipts')],
        ['route' => 'account.giving', 'label' => __('Regular giving')],
        ['route' => 'account.profile', 'label' => __('Profile')],
        ['route' => 'account.security', 'label' => __('Security')],
    ])->filter(fn (array $link) => Route::has($link['route'])) : collect();
@endphp

@auth
    @if ($layout === 'list')
        <div>
            <p class="px-3 pb-1 text-xs text-[var(--text-muted)]">{{ __('Signed in as :name', ['name' => auth()->user()->name]) }}</p>
            <ul>
                @foreach ($links as $link)
                    <li><a href="{{ route($link['route']) }}" @if (request()->routeIs($link['route'])) aria-current="page" @endif class="{{ $itemClass }}">{{ $link['label'] }}</a></li>
                @endforeach
                <li class="mt-1 border-t border-[var(--border)] pt-1">
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="{{ $itemClass }} text-[var(--text-muted)]">
                            <x-ui.icon name="arrow-right-start-on-rectangle" class="size-4" />
                            {{ __('Sign out') }}
                        </button>
                    </form>
                </li>
            </ul>
        </div>
    @else
        <details data-nav-dropdown data-no-hover class="group relative">
            <summary
                class="flex cursor-pointer list-none items-center gap-1.5 whitespace-nowrap rounded px-2 py-2 text-sm font-medium text-[var(--text-primary)] hover:text-[var(--brand-primary)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus-ring)]"
            >
                <x-ui.icon name="user-circle" class="size-5" />
                {{ __('Your account') }}
                <span class="sr-only">— {{ auth()->user()->name }}</span>
                <svg data-chevron class="size-4 transition-transform group-open:rotate-180" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.17l3.71-3.94a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd" />
                </svg>
            </summary>

            <div class="absolute right-0 top-full z-40 mt-1 min-w-56 rounded-[var(--radius-md)] border border-[var(--border)] bg-[var(--bg)] p-1 shadow-[var(--shadow-lg)]">
                <p class="truncate px-3 py-2 text-xs text-[var(--text-muted)]">{{ __('Signed in as :name', ['name' => auth()->user()->name]) }}</p>
                <ul>
                    @foreach ($links as $link)
                        <li><a href="{{ route($link['route']) }}" @if (request()->routeIs($link['route'])) aria-current="page" @endif class="{{ $itemClass }}">{{ $link['label'] }}</a></li>
                    @endforeach
                </ul>
                <hr class="my-1 border-[var(--border)]" aria-hidden="true">
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="{{ $itemClass }} text-[var(--text-muted)]">
                        <x-ui.icon name="arrow-right-start-on-rectangle" class="size-4" />
                        {{ __('Sign out') }}
                    </button>
                </form>
            </div>
        </details>
    @endif
@else
    <a
        href="{{ route('login') }}"
        class="{{ $layout === 'list' ? $itemClass : 'whitespace-nowrap rounded-md px-2 py-2 text-sm font-medium text-[var(--text-primary)] hover:text-[var(--brand-primary)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus-ring)]' }}"
    >{{ __('Sign in') }}</a>
@endauth
