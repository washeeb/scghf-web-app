<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * The public front door.
 *
 * ── Staff do not sign in here ───────────────────────────────────────────────
 *
 * This is the security decision in this file, and it is not about tidiness.
 *
 * Two-factor authentication is mandatory for staff (Blueprint §7.1) and it is
 * enforced by Filament, inside Filament's own login flow. A staff member who
 * authenticated at THIS form would hold a fully authenticated session having
 * shown one factor — and `canAccessPanel()` would then let them walk into the
 * admin panel, past the check, without anything having gone wrong that anybody
 * could see. A public login form is a bypass of the admin panel's MFA unless it
 * refuses staff.
 *
 * So it refuses them, and says where to go instead — but only AFTER the correct
 * password has been given. "This is a staff account" is information about an
 * address, and information about an address is not given to somebody who has
 * not proved they hold it.
 *
 * `LoginRequest::authenticate()` also constrains the attempt to donor accounts,
 * so a change here can never quietly reopen the path.
 */
class LoginController extends Controller
{
    public function show(): View
    {
        return view('auth.login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        // Before any password is checked, so a locked-out attacker gets nothing
        // — not even timing.
        $request->ensureIsNotRateLimited();

        $account = $request->account();

        if ($account !== null && $request->passwordIsCorrect()) {
            if ($account->isStaff()) {
                return redirect()
                    ->to(Filament::getPanel('admin')->getLoginUrl())
                    ->with('status', __('Staff accounts sign in here, where the second step is required.'));
            }

            /*
             * A suspended or deactivated account, with the right password.
             *
             * Told plainly, because the person is the account holder and a
             * generic "credentials do not match" would send them to the
             * password reset form, where they would fail again for a reason
             * nobody explains. It still costs an attempt against the rate
             * limiter — a correct password is a correct guess whatever the
             * account's state, and free guesses against suspended accounts
             * would make them an oracle.
             */
            if (! $account->is_active || $account->isSuspended()) {
                $request->recordFailure();

                return back()
                    ->withInput($request->only('email'))
                    ->withErrors(['email' => __('This account is closed. Please contact us if you think that is a mistake.')]);
            }

            /*
             * Two-factor is on, so the password alone is not a sign-in.
             *
             * Deliberately BEFORE `authenticate()`: the account is never put in
             * the guard, so there is no authenticated session for somebody to
             * navigate away from the challenge with. What exists is an id in
             * the session saying who is halfway through, which the challenge
             * re-reads from the database — a factor that can be skipped by
             * closing a tab is not a factor.
             *
             * Nothing is recorded in the login history yet either. A success
             * row written here would say somebody signed in when they had
             * produced one of the two things required.
             */
            if ($account->hasTwoFactorEnabled()) {
                $request->session()->put(TwoFactorChallengeController::PENDING, $account->getKey());
                $request->session()->put(TwoFactorChallengeController::REMEMBER, $request->boolean('remember'));

                return redirect()->route('two-factor.challenge');
            }
        }

        $request->authenticate();

        /*
         * A new session id for the authenticated session.
         *
         * Session fixation: without this, an id an attacker planted before
         * sign-in is still the id afterwards, and they are now signed in as
         * whoever used it.
         */
        $request->session()->regenerate();

        $user = $request->user();

        $user?->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->saveQuietly();

        return redirect()->intended(route('account.dashboard'));
    }

    /**
     * Sign out.
     *
     * All three lines matter. `logout()` clears the guard, `invalidate()`
     * destroys the session data, and `regenerateToken()` issues a new CSRF
     * token — without the last one, the token from the signed-in session stays
     * valid on the login form of the next person to use the browser.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home')->with('status', __('You have been signed out.'));
    }
}
