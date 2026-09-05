<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Events\TwoFactorChallengeFailed;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\TwoFactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * The second step, for a donor who has turned it on.
 *
 * ── Nobody is signed in while this page is open ─────────────────────────────
 *
 * The password has been checked and the account has NOT been authenticated. All
 * that exists is an id in the session saying which account is halfway through
 * signing in. That distinction is the whole point: if the guard were populated
 * here, the second factor would be a page somebody could simply navigate away
 * from — which is not a factor, it is a suggestion.
 *
 * ── It is rate limited, and that is not optional ────────────────────────────
 *
 * Six digits is a million possibilities, and an attacker reaching this page has
 * already got the password. Without a limit they can try all million. The limit
 * is keyed on the account AND the IP together — see `throttleKey()` for why the
 * session id, which was the obvious first choice, is the wrong one.
 */
class TwoFactorChallengeController extends Controller
{
    /** The session key holding who is halfway through. */
    public const PENDING = 'auth.two_factor.user';

    /** Whether "stay signed in" was ticked on the form before this one. */
    public const REMEMBER = 'auth.two_factor.remember';

    public function show(Request $request): View|RedirectResponse
    {
        $user = $this->pendingUser($request);

        return $user === null
            ? redirect()->route('login')
            : view('auth.two-factor-challenge', ['recoveryCodesLeft' => $user->remainingRecoveryCodes()]);
    }

    public function store(Request $request, TwoFactor $twoFactor): RedirectResponse
    {
        $user = $this->pendingUser($request);

        if ($user === null) {
            /*
             * The session expired while they were fetching their phone. Back to
             * the start rather than an error — the password is not still valid
             * here, so there is nothing to resume.
             */
            return redirect()->route('login')
                ->withErrors(['code' => __('That took too long. Please sign in again.')]);
        }

        $request->validate([
            'code' => ['required', 'string', 'max:64'],
        ]);

        $this->ensureIsNotRateLimited($request);

        $code = (string) $request->input('code');

        /*
         * A code, or a recovery code. One field for both, because asking
         * somebody who has lost their phone to first find the right form is a
         * bad moment to add a step — and the two are trivially distinguishable
         * by shape.
         */
        $secret = (string) $user->getAppAuthenticationSecret();
        $passed = $twoFactor->verify($secret, $code) || $user->consumeRecoveryCode($code);

        if (! $passed) {
            RateLimiter::hit($this->throttleKey($request), (int) config('security.lockout_seconds', 900));

            /*
             * Recorded as its own outcome. `LoginOutcome::TwoFactorFailed` has
             * existed since Module 1 with nothing writing it — and it is the
             * most interesting failure in the table, because it means somebody
             * got the password right and then could not produce the phone.
             */
            Event::dispatch(new TwoFactorChallengeFailed($user));

            throw ValidationException::withMessages([
                'code' => __('That code is not right. Check your authenticator app, or use a recovery code.'),
            ]);
        }

        RateLimiter::clear($this->throttleKey($request));

        $remember = (bool) $request->session()->pull(self::REMEMBER, false);
        $request->session()->forget(self::PENDING);

        Auth::login($user, $remember);

        // Session fixation, the same as the login form: the id somebody held
        // before authentication must not be the id they hold after it.
        $request->session()->regenerate();

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->saveQuietly();

        return redirect()->intended(route('account.dashboard'));
    }

    /**
     * The account halfway through signing in, if the session still says so.
     *
     * Re-read from the database every time rather than trusted from the
     * session, so an account suspended between the password and the code does
     * not get in on a stale copy of itself.
     */
    private function pendingUser(Request $request): ?User
    {
        $id = $request->session()->get(self::PENDING);

        if ($id === null) {
            return null;
        }

        $user = User::find($id);

        return $user?->isInGoodStanding() && $user->hasTwoFactorEnabled() ? $user : null;
    }

    private function ensureIsNotRateLimited(Request $request): void
    {
        $key = $this->throttleKey($request);

        if (! RateLimiter::tooManyAttempts($key, (int) config('security.rate_limits.login', 5))) {
            return;
        }

        throw ValidationException::withMessages([
            'code' => __('auth.throttle', [
                'seconds' => RateLimiter::availableIn($key),
                'minutes' => (int) ceil(RateLimiter::availableIn($key) / 60),
            ]),
        ]);
    }

    /**
     * Keyed on the ACCOUNT plus the IP — the same shape the login form uses.
     *
     * Not on the session id, which was the first attempt and is wrong for a
     * reason worth recording: a session id is not stable. Anything that
     * regenerates it — and authentication does, deliberately — moves the bucket,
     * so a limit keyed on it is a limit that resets whenever the thing it is
     * protecting makes progress. A control that can be shed by discarding a
     * cookie is not a control.
     *
     * Account alone would let anybody who knew a donor's password lock them out
     * of their own sign-in. IP alone puts a whole shared mobile gateway — which
     * in Ghana is most of the country — in one bucket. Both together is the
     * pair that neither denies service to a stranger nor gives an attacker
     * unlimited guesses, which is exactly the reasoning in LoginRequest.
     */
    private function throttleKey(Request $request): string
    {
        return 'two-factor|'.$request->session()->get(self::PENDING, 'none').'|'.$request->ip();
    }
}
