<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Communications\AccountNotifier;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\TwoFactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

/**
 * Turning the second step on and off, for a donor.
 *
 * Optional here, and mandatory for staff — Blueprint §7.1. A donor account
 * holds a giving history rather than the ability to move money, so requiring it
 * would be friction bought with nothing. Offering it is not: the people most
 * likely to want it are the ones giving regularly, and they are the ones whose
 * account is worth taking.
 *
 * ── The secret never reaches the database unproved ──────────────────────────
 *
 * Enrolment holds the candidate secret in the SESSION and writes it only after
 * a code generated from it has been verified. There is no half-enrolled row, so
 * `two_factor_secret` being populated always means a factor the person can
 * actually produce — which matters because `hasTwoFactorEnabled()` gates the
 * sign-in challenge, and a secret nobody can generate codes for would lock the
 * account out of itself.
 */
class TwoFactorController extends Controller
{
    /** Where the unconfirmed secret waits. */
    private const CANDIDATE = 'account.two_factor.candidate';

    /** Begin enrolment: a fresh secret, a QR code, and nothing saved. */
    public function create(Request $request, TwoFactor $twoFactor): View|RedirectResponse
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->route('login');
        }

        if ($user->hasTwoFactorEnabled()) {
            return redirect()->route('account.security');
        }

        /*
         * Kept across the round trip so the code the person types is checked
         * against the secret their app actually scanned. Regenerating it on the
         * POST would guarantee a mismatch.
         */
        $secret = $request->session()->get(self::CANDIDATE) ?? $twoFactor->generateSecret();
        $request->session()->put(self::CANDIDATE, $secret);

        $issuer = (string) setting('general.short_name', config('app.name'));

        return view('account.two-factor', [
            'secret' => $secret,
            'qrCode' => $twoFactor->qrCodeDataUri($secret, $issuer, (string) $user->email),
            'provisioningUri' => $twoFactor->provisioningUri($secret, $issuer, (string) $user->email),
        ]);
    }

    /** Prove the app is set up, and only then store anything. */
    public function store(Request $request, TwoFactor $twoFactor): RedirectResponse
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->route('login');
        }

        $request->validate([
            'code' => ['required', 'string'],
            /*
             * The password, even though they are already signed in.
             *
             * Adding a second factor to somebody else's account is a way to
             * lock them out of it — the attacker keeps the phone. So this is
             * gated the same way removing one is.
             */
            'current_password' => ['required', 'string', 'current_password'],
        ], [
            'current_password.current_password' => __('That is not your current password.'),
        ]);

        $secret = (string) $request->session()->get(self::CANDIDATE);

        if ($secret === '' || ! $twoFactor->verify($secret, (string) $request->input('code'))) {
            throw ValidationException::withMessages([
                'code' => __('That code is not right. Check the time on your phone is correct, then try again.'),
            ]);
        }

        $codes = $twoFactor->generateRecoveryCodes();

        $user->enableTwoFactor($secret, $codes);
        $request->session()->forget(self::CANDIDATE);

        $this->announce($user->refresh(), enabled: true);

        /*
         * The codes are flashed, so they are shown exactly once and are not in
         * the URL, not in the session afterwards, and not retrievable by
         * reloading. Somebody who does not write them down has to regenerate a
         * set, which is the honest outcome — a page that shows them again on
         * demand is a page that shows them to whoever opens the laptop next.
         */
        return redirect()->route('account.security')
            ->with('recoveryCodes', $codes)
            ->with('status', __('Two-factor authentication is on. Save these recovery codes now — they are not shown again.'));
    }

    /** Remove it. */
    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->route('login');
        }

        $request->validate([
            'current_password' => ['required', 'string', 'current_password'],
        ], [
            'current_password.current_password' => __('That is not your current password.'),
        ]);

        $user->disableTwoFactor();

        $this->announce($user->refresh(), enabled: false);

        return redirect()->route('account.security')
            ->with('status', __('Two-factor authentication is off. Signing in now needs only your password.'));
    }

    /**
     * A fresh set of recovery codes, invalidating the old ones.
     *
     * Needed when the set runs low, and after any single code has been used —
     * a code that has been typed into a screen somewhere is a code that may
     * have been seen.
     */
    public function regenerate(Request $request, TwoFactor $twoFactor): RedirectResponse
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->route('login');
        }

        $request->validate([
            'current_password' => ['required', 'string', 'current_password'],
        ], [
            'current_password.current_password' => __('That is not your current password.'),
        ]);

        if (! $user->hasTwoFactorEnabled()) {
            return redirect()->route('account.security');
        }

        $codes = $twoFactor->generateRecoveryCodes();
        $user->saveAppAuthenticationRecoveryCodes($codes);

        return redirect()->route('account.security')
            ->with('recoveryCodes', $codes)
            ->with('status', __('New recovery codes. The old ones no longer work.'));
    }

    /**
     * Record it, and tell the account holder.
     *
     * The OFF direction is the one that matters: removing the second factor is
     * what an attacker does once they are inside, and it is silent everywhere
     * else in the system.
     */
    private function announce(User $user, bool $enabled): void
    {
        try {
            app(AuditLogger::class)->record(
                $enabled ? 'auth.two_factor_enabled' : 'auth.two_factor_disabled',
                $enabled
                    ? 'Two-factor authentication was turned on.'
                    : 'Two-factor authentication was turned off.',
                subject: $user,
                causer: $user,
            );
        } catch (Throwable) {
            // Already done; the record must not undo it.
        }

        app(AccountNotifier::class)->sendTwoFactorChanged($user, $enabled);
    }
}
