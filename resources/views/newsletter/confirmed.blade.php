{{--
    After following the confirmation link.

    The same page whether the token matched or not — a token is burnt on use, so
    somebody following their link twice would otherwise be told it failed.
--}}
<x-site.page-shell :meta="$meta" :title="__('You are on the list')">
    <p class="max-w-2xl text-[var(--text-secondary)]">
        {{ $confirmed
            ? __('Thank you for confirming. You will hear from us when there is something worth saying.')
            : __('This link has already been used, or it has expired. If you are not receiving our updates, you can sign up again from any page.') }}
    </p>

    <p class="mt-6">
        <a class="font-semibold text-[var(--brand-primary)] hover:underline" href="{{ url('/') }}">
            {{ __('Back to the site') }}
        </a>
    </p>
</x-site.page-shell>
