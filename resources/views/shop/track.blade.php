{{--
    Finding an order without an account.

    Reference plus the email or phone it was placed with. A miss is one
    sentence whichever half was wrong, so the form confirms nothing about
    which references exist.
--}}
<x-site.page-shell :meta="$meta" :crumbs="$crumbs" :title="__('Find your order')">
    <form method="POST" action="{{ route('shop.track.lookup') }}" class="max-w-md space-y-5">
        @csrf

        <p class="text-[var(--text-secondary)]">{{ __('The reference is in your confirmation email. Give it with the email address or phone number you ordered with.') }}</p>

        <x-site.field name="reference" :label="__('Order reference')" placeholder="SCGHF-O-XXXXXXXX" autocomplete="off" required />
        <x-site.field name="contact" :label="__('Email or phone number')" autocomplete="email" required />

        <button
            type="submit"
            class="rounded-md bg-[var(--brand-secondary)] px-6 py-3 font-semibold text-[var(--text-on-secondary)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus-ring)]"
        >{{ __('Find it') }}</button>
    </form>
</x-site.page-shell>
