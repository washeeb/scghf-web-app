<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\PasswordPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * "I have forgotten my password."
 *
 * ── The response never says whether the address exists ──────────────────────
 *
 * Whatever happens — no such account, a staff account, a suppressed address,
 * a link sent — this returns the same sentence. That is the whole security
 * property of a forgotten-password form: without it, the form is a free service
 * for testing whether an address has an account here, which for a foundation
 * means a list of its donors.
 *
 * The cost is a real one and worth naming: somebody who mistypes their address
 * is told a link is on its way and it never arrives. The alternative costs
 * everybody else their privacy, so this is the trade every well-built form
 * makes. The `email_logs` row is what lets support answer "did it go?" without
 * the form having to.
 */
class PasswordResetController extends Controller
{
    public function request(): View
    {
        return view('auth.forgot-password');
    }

    /**
     * Issue a link, if there is somebody to issue it to.
     *
     * `Password::sendResetLink()` calls `sendPasswordResetNotification()` on the
     * user, which this application overrides to route through the dispatcher —
     * so a reset for a suppressed address produces a `suppressed` row explaining
     * itself rather than silence.
     */
    public function email(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'string', 'email', 'max:191'],
        ]);

        $address = Str::lower(trim((string) $request->input('email')));

        /*
         * Staff are excluded here as well as at the login form.
         *
         * A staff password reset that lands on the public form would let
         * somebody with access to a staff inbox set a new password and sign in
         * — and the public form is exactly where they would then use it. Staff
         * reset through the panel, where the second factor still applies.
         */
        $isPublicAccount = User::where('email', $address)
            ->where('type', UserType::Donor->value)
            ->exists();

        if ($isPublicAccount) {
            Password::sendResetLink(['email' => $address]);
        }

        return back()->with('status', __(
            'If an account exists for that address, a link to reset the password is on its way. It expires in an hour.'
        ));
    }

    /** The form the link opens. */
    public function edit(Request $request, string $token): View
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    /**
     * Set the new password.
     *
     * The broker validates the token against the address, checks it has not
     * expired, deletes it, and fires PasswordReset — which the audit listener
     * records. `changePassword()` then rotates the remember token and emails
     * the account holder, which is what makes a takeover visible to the person
     * it happened to.
     */
    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email', 'max:191'],
            'password' => ['required', 'string', 'confirmed', PasswordPolicy::rule()],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password): void {
                $user->changePassword($password);
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }

        return redirect()->route('login')->with('status', __(
            'Your password has been changed. You can sign in with it now.'
        ));
    }
}
