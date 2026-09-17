<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Communications\MessageDispatcher;
use App\Models\Newsletter;
use App\Models\Subscriber;
use App\Models\Suppression;
use App\Support\PageMeta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

/**
 * Joining and leaving the mailing list.
 *
 * ── Double opt-in, and it is not a preference ───────────────────────────────
 *
 * `site.newsletter_double_optin` is seeded on and the setting's own description
 * says why: single opt-in is the fastest way to destroy a sending domain's
 * reputation, and a foundation whose donation receipts stop arriving has a much
 * bigger problem than a smaller mailing list. Nothing is mailed until the
 * address has confirmed it wants to be.
 *
 * ── The whole flow existed and had no entry point ───────────────────────────
 *
 * `Subscriber::recordConsent()`, `confirm()` and `unsubscribe()` were written in
 * Phase 3, the tokens are generated on create, and `newsletter.confirm` was
 * seeded as a template. Nothing called any of it — and the footer's signup form
 * checks `Route::has('newsletter.subscribe')`, so it has rendered nothing on
 * every page of the site since Phase 4.
 *
 * ── The same answer whether or not the address is already on the list ───────
 *
 * "You are already subscribed" is an address-existence oracle: anybody can type
 * an email in and learn whether that person supports this foundation. On a
 * charity working with vulnerable people that is not a neutral fact, so the
 * response is identical either way and the difference is only in what is sent.
 */
class NewsletterController extends Controller
{
    public function subscribe(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:191'],
            'name' => ['nullable', 'string', 'max:191'],
            'source' => ['nullable', 'string', Rule::in(['footer', 'block', 'popup', 'website'])],
        ]);

        $email = mb_strtolower(trim($validated['email']));

        $this->enrol($email, $validated['name'] ?? null, $request, $validated['source'] ?? 'website');

        /*
         * A year-long, http-only "has subscribed" cookie. Not a session and
         * not the address — one bit, so the popup is never drawn again on
         * this device. That promise is per-device, which is the honest scope.
         */
        return back()
            ->withCookie(cookie('scghf_subscribed', '1', 60 * 24 * 365, null, null, null, true, false, 'lax'))
            ->with('status', __(
                'Thank you. Please check your inbox — there is a link to confirm, and we will not send '
                .'anything until you have followed it.'
            ));
    }

    /**
     * Confirming from the inbox.
     *
     * No session, because the link is opened from an email that may well be
     * read on a different device from the one that signed up. Keyed on the
     * token rather than the address: a URL containing somebody's email address
     * leaks it into every referrer header and browser history it passes
     * through.
     */
    public function confirm(string $token): View
    {
        $subscriber = Subscriber::query()->where('confirmation_token', $token)->first();

        if ($subscriber !== null) {
            $subscriber->confirm();
        }

        return view('newsletter.confirmed', [
            /*
             * The same page whether the token matched or not. A token is burnt
             * on use, so somebody who follows their link twice — which happens
             * constantly — would otherwise be told their confirmation failed.
             */
            'confirmed' => $subscriber !== null,
            'meta' => PageMeta::site(__('Subscription confirmed'), noindex: true),
        ]);
    }

    /**
     * Leaving.
     *
     * One click, no sign-in, no "are you sure". Every email carries this link
     * and it has to work first time: a friction-filled unsubscribe is how a
     * recipient reports the message as spam instead, and a spam complaint
     * damages delivery for every other message the foundation sends.
     */
    public function unsubscribe(string $token): View
    {
        $subscriber = Subscriber::query()->where('unsubscribe_token', $token)->first();

        $subscriber?->unsubscribe('one-click link');

        return view('newsletter.unsubscribed', [
            'subscriber' => $subscriber,
            'meta' => PageMeta::site(__('Unsubscribed'), noindex: true),
        ]);
    }

    /**
     * The preference centre.
     *
     * Which of the foundation's lists to hear from, or none. Reached by the
     * token in every email, so it needs no account; a stale token shows the
     * same page as a used unsubscribe link rather than an error.
     */
    public function preferences(string $token): View
    {
        $subscriber = Subscriber::query()->where('unsubscribe_token', $token)->first();

        return view('newsletter.preferences', [
            'subscriber' => $subscriber,
            'token' => $token,
            'newsletters' => Newsletter::query()->where('is_active', true)->orderBy('sort_order')->get(),
            'meta' => PageMeta::site(__('Your email preferences'), noindex: true),
        ]);
    }

    public function updatePreferences(Request $request, string $token): RedirectResponse
    {
        $subscriber = Subscriber::query()->where('unsubscribe_token', $token)->first();

        if ($subscriber === null) {
            return redirect()->route('newsletter.preferences', $token);
        }

        $data = $request->validate([
            'topics' => ['nullable', 'array'],
            'topics.*' => ['string', Rule::in(Newsletter::query()->pluck('topic')->all())],
            'name' => ['nullable', 'string', 'max:191'],
            'stop' => ['nullable', 'boolean'],
        ]);

        if ($request->boolean('stop')) {
            $subscriber->unsubscribe('preference centre');

            return redirect()->route('newsletter.preferences', $token)->with('status', __('Done. We will not send you any more updates.'));
        }

        $subscriber->forceFill([
            'topics' => array_values($data['topics'] ?? []),
            'name' => filled($data['name'] ?? null) ? $data['name'] : $subscriber->name,
        ])->save();

        if ($subscriber->status === Subscriber::STATUS_UNSUBSCRIBED) {
            // Choosing a topic after unsubscribing is asking to come back. The
            // consent is the tick on this page, recorded like the first one.
            $subscriber->forceFill(['status' => Subscriber::STATUS_CONFIRMED, 'unsubscribed_at' => null, 'unsubscribe_reason' => null])->save();
            $subscriber->recordConsent(__('Resubscribed from the preference centre.'), $request->ip(), $request->fullUrl());
        }

        return redirect()->route('newsletter.preferences', $token)->with('status', __('Saved. You will hear from us about what you chose.'));
    }

    /**
     * Create or revive the record, and send the confirmation.
     *
     * ⚠ A suppressed address is never re-enrolled. `suppressions` holds
     * addresses that hard-bounced or reported a message as spam, and mailing
     * one again is the single fastest route to a blocked sending domain — so a
     * signup for one is accepted silently and does nothing. Telling the person
     * would be the same existence oracle described above.
     */
    private function enrol(string $email, ?string $name, Request $request, string $source = 'website'): void
    {
        $suppressed = Suppression::query()
            ->where('channel', Suppression::CHANNEL_EMAIL)
            ->where('address', $email)
            ->get()
            // `isActive()` and not a bare row check: a suppression can expire,
            // and a repeated soft bounce that lapsed six months ago is not a
            // reason to refuse somebody who is asking to hear from us.
            ->contains(fn (Suppression $suppression): bool => $suppression->isActive());

        if ($suppressed) {
            return;
        }

        $subscriber = Subscriber::query()->firstOrNew(['email' => $email]);

        if ($subscriber->exists && $subscriber->status === Subscriber::STATUS_CONFIRMED) {
            // Already on the list and happy. Nothing to send, nothing to say —
            // and re-sending a confirmation to a confirmed address is a way for
            // a stranger to make somebody's inbox receive mail on demand.
            return;
        }

        if ($subscriber->exists) {
            /*
             * Somebody who unsubscribed and has come back. The status returns
             * to pending and a fresh token is issued, so they confirm again —
             * their previous unsubscribe is not treated as consent.
             */
            $subscriber->forceFill([
                'status' => Subscriber::STATUS_PENDING,
                'confirmation_token' => Str::random(48),
                'unsubscribed_at' => null,
                'unsubscribe_reason' => null,
            ]);
        }

        $subscriber->fill(['name' => $name, 'source' => $source])->save();

        $subscriber->recordConsent(
            $this->consentText(),
            $request->ip(),
            $request->headers->get('referer'),
        );

        $this->sendConfirmation($subscriber);
    }

    private function sendConfirmation(Subscriber $subscriber): void
    {
        try {
            app(MessageDispatcher::class)->queueEmail('newsletter.confirm', $subscriber->email, [
                'name' => $subscriber->name ?? '',
                'confirm_url' => route('newsletter.confirm', $subscriber->confirmation_token),
                'unsubscribe_url' => route('newsletter.unsubscribe', $subscriber->unsubscribe_token),
            ]);
        } catch (Throwable) {
            /*
             * The record is saved with its consent evidence. A confirmation
             * that could not be queued means they stay pending and are never
             * mailed — which is the safe end of that failure, and the signup
             * page says a link is coming rather than claiming it arrived.
             */
        }
    }

    private function consentText(): string
    {
        return (string) setting(
            'compliance.newsletter_consent_text',
            __('I would like to receive email updates from :name, and I can unsubscribe at any time.', [
                'name' => setting('general.legal_name', setting('general.short_name', config('app.name'))),
            ]),
        );
    }
}
