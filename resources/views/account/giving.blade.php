{{--
    A donor's standing gifts.

    Rendered inside the account shell when signed in, and as a plain page
    when reached from the signed link in an email — in which case every form
    posts to a signed URL of its own, so a change is as authenticated as the
    page that offered it.

    Cancel is behind a <details> with a reason box, so it takes a deliberate
    second step; pause and resume are one press, because either is reversible.
--}}
@php
    $action = fn (string $route, $subscription): string => $signed
        ? Illuminate\Support\Facades\URL::temporarySignedRoute($route, now()->addDays(60), ['subscription' => $subscription->ulid])
        : route($route, $subscription);

    $intervalWord = fn ($s): string => match ($s->interval) {
        'weekly' => __('every week'),
        'quarterly' => __('every three months'),
        'annually' => __('every year'),
        default => __('every month'),
    };
@endphp


@if ($signed)
    <x-site.page-shell :meta="$meta" :title="__('Your regular gift')" :crumbs="[['label' => __('Home'), 'url' => url('/')], ['label' => __('Your regular gift'), 'url' => null]]">
        <div class="max-w-3xl space-y-6">
            <x-site.status />
            @include('account.partials.subscriptions', ['subscriptions' => $subscriptions, 'action' => $action, 'intervalWord' => $intervalWord, 'signed' => true])
        </div>
    </x-site.page-shell>
@else
    <x-site.account-layout :title="__('Regular giving')" :user="auth()->user()">
        @include('account.partials.subscriptions', ['subscriptions' => $subscriptions, 'action' => $action, 'intervalWord' => $intervalWord, 'signed' => false])
    </x-site.account-layout>
@endif
