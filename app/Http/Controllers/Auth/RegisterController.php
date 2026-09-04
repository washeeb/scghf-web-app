<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Creating a public donor account.
 *
 * ── What an account is for here ─────────────────────────────────────────────
 *
 * Nothing on this site requires one. A donor can give, get a receipt and never
 * come back, and that is the majority path — which is why `donors` is a
 * separate table from `users`. An account exists to see your own giving
 * history, manage a recurring gift, and change what we send you.
 *
 * So registration must never become a step in the donation flow. If it ever
 * appears between a donor and the payment button, it is in the wrong place.
 */
class RegisterController extends Controller
{
    public function show(): View
    {
        $this->ensureRegistrationIsOpen();

        return view('auth.register');
    }

    public function store(RegisterRequest $request): RedirectResponse
    {
        $this->ensureRegistrationIsOpen();

        /*
         * One transaction around the account and its consent timestamp.
         *
         * Not for speed. `marketing_consent_at` is the evidence that consent
         * was given and when — Act 843 asks for exactly that — and a user
         * created without it is a user we are contacting with no record of
         * permission.
         */
        $user = DB::transaction(function () use ($request): User {
            $user = new User([
                'name' => (string) $request->validated('name'),
                'email' => (string) $request->validated('email'),
                'password' => (string) $request->validated('password'),
                'phone' => $request->filled('phone') ? (string) $request->input('phone') : null,
                'type' => UserType::Donor,
                'accepts_email_marketing' => $request->boolean('accepts_email_marketing'),
                'accepts_sms_marketing' => $request->boolean('accepts_sms_marketing'),
            ]);

            if ($request->boolean('accepts_email_marketing') || $request->boolean('accepts_sms_marketing')) {
                $user->marketing_consent_at = now();
            }

            $user->save();

            /*
             * The public role. It carries no admin permissions at all — the
             * seeder defines it as an empty set on purpose — and exists so that
             * "which accounts are donors" is answerable by role as well as by
             * type, and so a permission can be granted to donors later without
             * a migration.
             */
            $user->assignRole('Donor');

            return $user;
        });

        /*
         * The verification email goes through AccountNotifier, which sends via
         * MessageDispatcher — so it is suppression-checked and logged like
         * everything else. If it throws, the transaction above has already
         * committed and the account exists: better a real account whose email
         * failed, which support can re-send, than a rolled-back registration
         * the donor has to repeat.
         */
        $user->sendEmailVerificationNotification();

        app(AuditLogger::class)->record(
            'auth.account_created',
            'A public account was created.',
            subject: $user,
            causer: $user,
        );

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('verification.notice');
    }

    /**
     * A 404, not a 403.
     *
     * When registration is closed the form does not exist as far as the public
     * is concerned. A 403 would announce that there is something here to come
     * back for, which is the opposite of what closing it during a spam wave is
     * trying to achieve.
     */
    private function ensureRegistrationIsOpen(): void
    {
        if (! config('security.accounts.registration_open', true)) {
            throw new NotFoundHttpException;
        }
    }
}
