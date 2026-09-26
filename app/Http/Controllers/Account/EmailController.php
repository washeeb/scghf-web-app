<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Communications\AccountNotifier;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Throwable;

/**
 * Changing the address on an account, safely.
 *
 * ── Three things have to be true before the address moves ───────────────────
 *
 *   1. the CURRENT PASSWORD, because a stolen session is not consent
 *   2. the NEW ADDRESS proves itself by opening a link
 *   3. the OLD ADDRESS is told, immediately, and given a link to stop it
 *
 * Each closes a different half of the same attack. Without (1) a session left
 * open on a shared computer is enough. Without (2) somebody can point the
 * account at an inbox that does not exist and lock everybody out of it,
 * including themselves. Without (3) the takeover is silent — and (3) is the
 * only one that reaches the person whose account it is, so it goes out on the
 * REQUEST rather than on the completion.
 *
 * Until (2) happens, `email` is untouched. An attacker who has done everything
 * up to here has changed nothing and has left a warning in the owner's inbox.
 */
class EmailController extends Controller
{
    public function request(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->route('login');
        }

        $validated = $request->validate([
            /*
             * Re-authentication. This is the field that makes a session left
             * open on an internet café machine insufficient to take the account
             * — and it is the reason this is not a profile field.
             */
            'current_password' => ['required', 'string', 'current_password'],

            'email' => [
                'required', 'string', 'email:rfc', 'max:191', 'different:'.$user->email,
                // Against `email` AND `pending_email`, so two people cannot
                // race for one address and hit the unique index as a 500.
                Rule::unique('users', 'email'),
                Rule::unique('users', 'pending_email')->ignore($user->getKey()),
            ],
        ], [
            'current_password.current_password' => __('That is not your current password.'),
            'email.different' => __('That is already the address on this account.'),
        ]);

        $user->requestEmailChange((string) $validated['email']);

        $notifier = app(AccountNotifier::class);

        // The confirmation first, then the warning. If the second throws, the
        // person who asked has still been given the means to finish — and the
        // audit entry below records the request either way.
        $notifier->sendEmailChangeConfirmation($user);

        try {
            $notifier->sendEmailChangeAlert($user);
        } catch (Throwable) {
            /*
             * The old address may be suppressed — a hard bounce is exactly why
             * somebody would be changing it. The dispatcher records that
             * refusal as a row, which is what support reads; it must not stop
             * the person moving to a working address.
             */
        }

        $this->audit(
            'auth.email_change_requested',
            sprintf('A change of email address to %s was requested.', $validated['email']),
            $user,
        );

        return back()->with('status', __(
            'Check :email for a link to confirm the change. Until you open it, your address stays as it is.',
            ['email' => $validated['email']],
        ));
    }

    /**
     * The link from the new address.
     *
     * Not behind `auth`, for the same reason the verification link is not:
     * somebody who asked on a phone may open it on a laptop, and bouncing them
     * to a sign-in form at that moment loses them. The signature is the
     * authentication, and the hash ties the link to the address it was issued
     * for — so a link generated for one pending address cannot confirm a
     * different one requested afterwards.
     */
    public function confirm(Request $request, string $ulid, string $hash): RedirectResponse
    {
        $user = User::where('ulid', $ulid)->first();

        if ($user === null
            || ! $user->hasPendingEmailChange()
            || ! hash_equals(sha1((string) $user->pending_email), $hash)) {
            throw new AccessDeniedHttpException('This link is no longer valid for this account.');
        }

        $previous = (string) $user->email;

        if (! $user->completeEmailChange()) {
            return redirect()->route('account.profile')->with('status', __(
                'That address has been taken by another account since you asked, so nothing was changed.'
            ));
        }

        $this->audit(
            'auth.email_changed',
            sprintf('The account address was changed from %s to %s.', $previous, $user->email),
            $user,
        );

        /*
         * Signed in already: back to the profile. Otherwise the sign-in form —
         * never signed in from a link in an email, which would make that email
         * a credential.
         */
        return $request->user()?->is($user)
            ? redirect()->route('account.profile')->with('status', __('Your email address has been changed.'))
            : redirect()->route('login')->with('status', __('Your email address has been changed. Sign in with it.'));
    }

    /**
     * "That was not me."
     *
     * Reachable from the warning sent to the OLD address, with no sign-in
     * required — the whole point is that it works for somebody who may be
     * locked out of their own session. It only ever cancels; it grants nothing.
     */
    public function cancel(Request $request, string $ulid, string $hash): RedirectResponse
    {
        $user = User::where('ulid', $ulid)->first();

        if ($user === null || ! hash_equals(sha1((string) $user->pending_email), $hash)) {
            throw new AccessDeniedHttpException('This link is no longer valid for this account.');
        }

        $attempted = (string) $user->pending_email;
        $user->cancelEmailChange();

        $this->audit(
            'auth.email_change_cancelled',
            sprintf('A requested change of address to %s was cancelled from the old address.', $attempted),
            $user,
        );

        /*
         * Signed out everywhere, because of what cancelling MEANS.
         *
         * Somebody had to be signed in to request the change. If the account
         * holder is saying it was not them, then whoever did it still holds a
         * session — and leaving that session alive would let them simply ask
         * again. This is the one place a cancel button ends other people's
         * sessions, and it is the right one.
         */
        $user->forceFill(['remember_token' => Str::random(60)])->save();

        if ($request->user()?->is($user)) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return redirect()->route('password.request')->with('status', __(
            'The change has been cancelled and every signed-in session has been ended. '
            .'Set a new password now — whoever asked for that change knew yours.'
        ));
    }

    private function audit(string $event, string $description, User $user): void
    {
        try {
            app(AuditLogger::class)->record($event, $description, subject: $user, causer: $user);
        } catch (Throwable) {
            // The change has already happened; auditing must not undo it.
        }
    }
}
