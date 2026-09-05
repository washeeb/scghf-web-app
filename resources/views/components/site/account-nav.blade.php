{{--
    "Sign in" or "Your account", in the header.

    ── Why this is a component and not a menu item ─────────────────────────────

    Everything else in the header comes from the `menus` tables, because it is
    content. This is not: it is the state of the current session, and it changes
    per visitor rather than per edit. The menu tables can already express
    "guests only" and "signed-in only" through `visible_to`, so the foundation
    CAN add its own account links there — this is the one that must exist
    whether they do or not.

    Signing out is a POST with a token. A GET sign-out link can be triggered by
    any page that embeds an image pointing at it, which is a small denial of
    service and a real annoyance.
--}}
@auth
    <div class="flex items-center gap-2">
        <a
            href="{{ route('account.dashboard') }}"
            class="rounded-md px-2 py-2 text-sm font-medium text-[var(--text-primary)] hover:text-[var(--brand-primary)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus-ring)]"
        >
            {{ __('Your account') }}
            <span class="sr-only">— {{ auth()->user()->name }}</span>
        </a>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button
                type="submit"
                class="rounded-md px-2 py-2 text-sm text-[var(--text-muted)] hover:text-[var(--text-primary)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus-ring)]"
            >{{ __('Sign out') }}</button>
        </form>
    </div>
@else
    <a
        href="{{ route('login') }}"
        class="rounded-md px-2 py-2 text-sm font-medium text-[var(--text-primary)] hover:text-[var(--brand-primary)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus-ring)]"
    >{{ __('Sign in') }}</a>
@endauth
