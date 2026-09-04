<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Proving that the address on an account belongs to the person holding it.
 *
 * ── Why this gates the giving history rather than the whole account ─────────
 *
 * An unverified account can sign in, change its own password and correct its
 * own name. What it cannot do is see donations — because `donors` is matched on
 * email address, and the giving history attached to an address is the one thing
 * here that somebody could steal by typing a stranger's address into the
 * sign-up form.
 *
 * So verification is not a formality gating the front door. It is the step that
 * earns the history, and `User::claimDonorRecord()` refuses to run before it.
 */
class EmailVerificationController extends Controller
{
    /** "We have sent you a link." */
    public function notice(Request $request): View|RedirectResponse
    {
        return $request->user()?->hasVerifiedEmail()
            ? redirect()->route('account.dashboard')
            : view('auth.verify-email');
    }

    /**
     * The link itself.
     *
     * Deliberately NOT behind the `auth` middleware. Somebody who registers on
     * a phone and opens the link on a laptop is not signed in there, and
     * bouncing them to a login form at that moment loses a good proportion of
     * them. The signature is the authentication: it is generated with the
     * application key, it cannot be produced without it, and it expires.
     *
     * `signed` middleware validates the signature and the expiry. The hash
     * check below is the second half: it ties the link to the address the
     * account had WHEN the link was issued, so a link generated before an
     * address change cannot verify the new one.
     */
    public function verify(Request $request, string $ulid, string $hash): RedirectResponse
    {
        $user = User::where('ulid', $ulid)->first();

        if ($user === null || ! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            throw new AccessDeniedHttpException(
                'This verification link is not valid for this account.'
            );
        }

        if ($user->hasVerifiedEmail()) {
            return $this->afterVerification($request, $user, __('Your email address was already confirmed.'));
        }

        $user->markEmailAsVerified();

        Event::dispatch(new Verified($user));

        /*
         * Now — and only now — attach the giving record for this address.
         *
         * Returns null when the record already belongs to a different account,
         * which is a merge decision for staff rather than something to resolve
         * silently inside a request.
         */
        $user->claimDonorRecord();

        return $this->afterVerification($request, $user, __('Thank you — your email address is confirmed.'));
    }

    /** Send it again. Throttled by the `verification` limiter on the route. */
    public function resend(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user === null || $user->hasVerifiedEmail()) {
            return redirect()->route('account.dashboard');
        }

        $user->sendEmailVerificationNotification();

        return back()->with('status', __('We have sent another link to :email.', ['email' => $user->email]));
    }

    /**
     * Where somebody lands after the link works.
     *
     * Signed in already: their dashboard. Not signed in — the laptop case above
     * — the login form, with the confirmation shown there, because signing them
     * in from a link in an email would make that email a credential.
     */
    private function afterVerification(Request $request, User $user, string $message): RedirectResponse
    {
        $isTheSamePerson = $request->user()?->is($user) ?? false;

        return redirect()
            ->route($isTheSamePerson ? 'account.dashboard' : 'login')
            ->with('status', $message);
    }
}
