<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\PasswordUpdateRequest;
use App\Support\AuditLogger;
use App\Support\Sessions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * The page that answers "is anybody else using my account?"
 *
 * `login_histories` has held this data since Module 1 and nothing showed it to
 * the person it is about. A row saying an account was signed into from Lagos at
 * 3am is only useful if the account holder can see it — the audit trail is for
 * accountability after the fact, this is for catching it while it is happening.
 */
class SecurityController extends Controller
{
    public function show(Request $request): View
    {
        $user = $request->user();

        /*
         * Twenty, and the failures as well as the successes.
         *
         * A run of failed attempts against your address followed by one success
         * is the shape of a password that was eventually guessed, and it is
         * invisible if the page only lists the times somebody got in.
         */
        $history = $user === null
            ? collect()
            : $user->loginHistories()->limit(20)->get();

        return view('account.security', [
            'user' => $user,
            'history' => $history,
            'openSessions' => $user === null ? 0 : Sessions::countFor($user),
        ]);
    }

    /**
     * Sign out everywhere else.
     *
     * The password is asked for, because the person pressing this may be
     * the reason it is needed: somebody who found the laptop open must not
     * be able to lock the owner out of their own other devices.
     */
    public function logoutEverywhere(Request $request): RedirectResponse
    {
        $request->validate(['current_password' => ['required', 'current_password']]);

        $user = $request->user();

        if ($user === null) {
            return redirect()->route('login');
        }

        $ended = Sessions::revokeAll($user, $request->session()->getId());

        app(AuditLogger::class)->record(
            'auth.sessions_revoked',
            sprintf('The account holder signed out of %d other device(s).', $ended),
            subject: $user,
            causer: $user,
        );

        return back()->with('status', trans_choice(
            '{0}No other devices were signed in. This one stays.|{1}One other device has been signed out. This one stays.|[2,*]:count other devices have been signed out. This one stays.',
            $ended,
        ));
    }

    public function updatePassword(PasswordUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->route('login');
        }

        // Rotates the remember token and emails the account holder. Both matter
        // here: if the reason for this change is a stolen password, whoever
        // stole it holds a remember cookie that is valid until it rotates.
        $user->changePassword((string) $request->validated('password'));

        app(AuditLogger::class)->record(
            'auth.password_changed',
            'The account holder changed their own password.',
            subject: $user,
            causer: $user,
        );

        /*
         * Keep THIS session signed in.
         *
         * Rotating the remember token invalidates the cookie for every other
         * device, which is the point — but it would also sign this browser out
         * on the next request, which reads as "the change failed" to the person
         * who just made it.
         */
        Auth::logoutOtherDevices((string) $request->validated('password'));
        $request->session()->regenerate();

        return back()->with('status', __(
            'Your password has been changed, and you have been signed out everywhere else.'
        ));
    }
}
