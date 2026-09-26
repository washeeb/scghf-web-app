<?php

declare(strict_types=1);

namespace App\Communications;

use App\Models\EmailLog;
use App\Models\LoginHistory;
use App\Models\User;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * The four emails a public account sends about itself.
 *
 * ── Why these do not go through the outbox ──────────────────────────────────
 *
 * Everything else in this application queues: a receipt, a campaign, an event
 * reminder. Those are messages ABOUT something that happened. These four are
 * part of an interaction somebody is in the middle of — a person is looking at
 * their inbox right now, having just been told to check it — and the outbox
 * drains on a cron tick, which on this host means up to a minute, and on a
 * backlog means considerably longer.
 *
 * A verification link that arrives in ninety seconds is a donor who has already
 * closed the tab. So these call `sendEmailNow`, which is still the dispatcher:
 * the suppression list, the logging and the single-door rule all hold. The only
 * thing skipped is the wait.
 *
 * The hourly cap is not skipped either, incidentally — `SendThrottle` counts
 * rows in `email_logs`, and `sendEmailNow` writes one. A password reset sent
 * directly still spends the account's mail allowance, so the outbox sees a
 * smaller budget rather than an ignored one.
 *
 * ── Why failures are loud, except one ───────────────────────────────────────
 *
 * A missing or deactivated template throws, because the alternative is an
 * account that can never be verified and nothing anywhere saying why. The new
 * device alert is the exception: it is an advisory, and an advisory must never
 * be the reason somebody cannot sign in.
 */
class AccountNotifier
{
    public function __construct(private readonly MessageDispatcher $dispatcher) {}

    /**
     * The link that proves somebody owns the address they registered with.
     *
     * Signed and expiring. The signature is what stops the link being edited
     * into somebody else's account id, and the expiry is what stops a forwarded
     * email being a standing key.
     *
     * Keyed by ULID rather than the auto-increment id — §1.1's rule, and here
     * it also means a guessed link is a guessed 26-character identifier rather
     * than "try 1, then 2, then 3".
     */
    public function sendEmailVerification(User $user): EmailLog
    {
        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes($this->linkLifetime()),
            ['ulid' => $user->ulid, 'hash' => sha1($user->getEmailForVerification())],
        );

        return $this->dispatcher->sendEmailNow('account.verify_email', (string) $user->email, [
            'name' => $user->name,
            'verify_url' => $url,
            'expires_in' => $this->linkLifetimeInWords(),
        ], ['to_name' => $user->name, 'user_id' => $user->getKey()]);
    }

    /**
     * The password reset link.
     *
     * The email address travels in the URL alongside the token because
     * Laravel's broker validates the pair, not the token alone — a token is
     * only meaningful against the address it was issued for.
     */
    public function sendPasswordReset(User $user, string $token): EmailLog
    {
        $url = route('password.reset', ['token' => $token, 'email' => $user->email]);

        return $this->dispatcher->sendEmailNow('account.password_reset', (string) $user->email, [
            'name' => $user->name,
            'reset_url' => $url,
            'expires_in' => $this->resetLifetimeInWords(),
        ], ['to_name' => $user->name, 'user_id' => $user->getKey()]);
    }

    /**
     * Confirmation that the password changed.
     *
     * Sent after the change, to the address on the account, and it is the only
     * thing that turns a silent account takeover into one the owner finds out
     * about. Worth sending even though nobody asked for it — especially then.
     */
    public function sendPasswordChanged(User $user): EmailLog
    {
        return $this->dispatcher->sendEmailNow('account.password_changed', (string) $user->email, [
            'name' => $user->name,
            'changed_at' => now()->format('j F Y \a\t H:i'),
        ], ['to_name' => $user->name, 'user_id' => $user->getKey()]);
    }

    /**
     * "Your account was signed into from a device it has not been used on."
     *
     * Best effort, and deliberately so. This runs inside the sign-in path, and
     * the alert existing must never be the reason a sign-in fails — the same
     * stance RecordAuthenticationEvent takes about its own writes.
     */
    public function sendNewDeviceAlert(User $user, LoginHistory $entry): ?EmailLog
    {
        if (! config('security.accounts.alert_on_new_device', true)) {
            return null;
        }

        try {
            return $this->dispatcher->sendEmailNow('account.new_device', (string) $user->email, [
                'name' => $user->name,
                'signed_in_at' => $entry->created_at?->format('j F Y \a\t H:i') ?? now()->format('j F Y \a\t H:i'),
                'device' => $this->describeDevice($entry),
                'ip_address' => $entry->ip_address ?? '—',
                'security_url' => route('account.security'),
            ], ['to_name' => $user->name, 'user_id' => $user->getKey()]);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The link that proves somebody owns the address they are moving to.
     *
     * Sent to the NEW address, and it is the only thing that actually performs
     * the change. Everything up to this point has written `pending_email` and
     * left the account alone.
     */
    public function sendEmailChangeConfirmation(User $user): EmailLog
    {
        $pending = (string) $user->pending_email;

        $url = URL::temporarySignedRoute(
            'account.email.confirm',
            now()->addMinutes($user->emailChangeLifetime()),
            ['ulid' => $user->ulid, 'hash' => sha1($pending)],
        );

        return $this->dispatcher->sendEmailNow('account.email_change_confirm', $pending, [
            'name' => $user->name,
            'confirm_url' => $url,
            'new_email' => $pending,
            'expires_in' => $this->linkLifetimeInWords(),
        ], ['to_name' => $user->name, 'user_id' => $user->getKey()]);
    }

    /**
     * The warning to the address the account is moving AWAY from.
     *
     * ── This is the control, not a courtesy ─────────────────────────────────
     *
     * Changing the address is how a stolen session becomes permanent: password
     * resets follow the address, so once it moves, the real owner has no way
     * back that does not involve a person. This email is the one moment they
     * can stop it, and it goes to the inbox they still control — so it is sent
     * on the REQUEST, not on the completion, and it carries a cancel link that
     * needs nothing but the click.
     *
     * Sent even when the request came from the account holder themselves, who
     * will ignore it. An alert nobody ever receives in the innocent case is one
     * that looks like a phishing attempt on the day it matters.
     */
    public function sendEmailChangeAlert(User $user): EmailLog
    {
        $url = URL::temporarySignedRoute(
            'account.email.cancel',
            now()->addMinutes($user->emailChangeLifetime()),
            ['ulid' => $user->ulid, 'hash' => sha1((string) $user->pending_email)],
        );

        return $this->dispatcher->sendEmailNow('account.email_change_alert', (string) $user->email, [
            'name' => $user->name,
            'new_email' => (string) $user->pending_email,
            'cancel_url' => $url,
            'expires_in' => $this->linkLifetimeInWords(),
        ], ['to_name' => $user->name, 'user_id' => $user->getKey()]);
    }

    /**
     * "Two-factor authentication is now on" — or off.
     *
     * Both directions, and the OFF one matters more: switching the second
     * factor off is what an attacker does once they are in, and it is silent
     * everywhere else. Sent to the address on the account, which after a change
     * is the new one — so an attacker who has taken both steps has still left a
     * message somewhere.
     */
    public function sendTwoFactorChanged(User $user, bool $enabled): ?EmailLog
    {
        try {
            return $this->dispatcher->sendEmailNow(
                $enabled ? 'account.two_factor_enabled' : 'account.two_factor_disabled',
                (string) $user->email,
                [
                    'name' => $user->name,
                    'changed_at' => now()->format('j F Y \a\t H:i'),
                    'security_url' => route('account.security'),
                ],
                ['to_name' => $user->name, 'user_id' => $user->getKey()],
            );
        } catch (Throwable) {
            // Advisory. It must not be the reason a person cannot finish
            // turning their own second factor on.
            return null;
        }
    }

    /**
     * "Chrome on Android (mobile)" — enough for somebody to recognise
     * themselves, and nothing that identifies the machine.
     *
     * Every part can be null: a user agent is a string the browser volunteers
     * and some of them volunteer nothing useful.
     */
    private function describeDevice(LoginHistory $entry): string
    {
        $parts = array_filter([
            $entry->browser,
            $entry->platform ? 'on '.$entry->platform : null,
            $entry->device_type ? '('.$entry->device_type.')' : null,
        ]);

        return $parts === [] ? 'An unrecognised device' : implode(' ', $parts);
    }

    private function linkLifetime(): int
    {
        return (int) config('security.accounts.link_lifetime_minutes', 60);
    }

    private function linkLifetimeInWords(): string
    {
        return now()->addMinutes($this->linkLifetime())->diffForHumans(now(), syntax: true, short: false, parts: 1);
    }

    /**
     * The reset window comes from the password broker, not from our own config.
     *
     * Two numbers describing one expiry is a bug waiting for somebody to change
     * the wrong one — and the number the email quotes has to be the number the
     * broker actually enforces, or the message is a lie in either direction.
     */
    private function resetLifetimeInWords(): string
    {
        $minutes = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60);

        return now()->addMinutes($minutes)->diffForHumans(now(), syntax: true, short: false, parts: 1);
    }
}
