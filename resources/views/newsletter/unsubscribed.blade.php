{{--
    After one click on the unsubscribe link in an email.

    No sign-in, no confirmation step, no "are you sure". A friction-filled
    unsubscribe is how a recipient reports the message as spam instead — and a
    spam complaint damages delivery for every other message the foundation
    sends, receipts included.
--}}
<x-site.page-shell :meta="$meta" :title="__('You have been unsubscribed')">
    <p class="max-w-2xl text-[var(--text-secondary)]">
        {{ $subscriber
            ? __('Done. We will not send you any more updates. Donation receipts and replies to messages you send us are separate, and will still reach you.')
            : __('This link has already been used. You are not on our mailing list.') }}
    </p>

    <p class="mt-6">
        <a class="font-semibold text-[var(--brand-primary)] hover:underline" href="{{ url('/') }}">
            {{ __('Back to the site') }}
        </a>
    </p>
</x-site.page-shell>
