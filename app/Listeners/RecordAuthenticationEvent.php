<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Communications\AccountNotifier;
use App\Enums\LoginOutcome;
use App\Models\LoginHistory;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Str;
use Throwable;

/**
 * Writes what happened at the front door into `login_histories` and the audit
 * trail.
 *
 * Both were built in earlier phases and neither had anything emitting into it:
 * `login_histories` has existed since Module 1 with no writer, and the
 * `auth.login`, `auth.login_failed` and `auth.locked_out` events have been
 * declared in config/system.php since Module 8 with nothing recording them.
 *
 * ── Why two tables rather than one ──────────────────────────────────────────
 *
 * They answer different questions and have different lives.
 *
 * `login_histories` is the DONOR-AND-STAFF-FACING record — "here is where your
 * account was signed into" — and it holds a device fingerprint-ish summary so a
 * person can recognise their own sessions. It is the thing a suspicious-login
 * email links to.
 *
 * `audit_logs` is the ACCOUNTABILITY record. It is hash-chained, append-only
 * and never swept, and it holds an actor and an event rather than a device.
 *
 * ── A failed login is recorded without confirming the account exists ────────
 *
 * `email_attempted` is stored on every failure, including for addresses that
 * have no account. That is deliberate: a credential-spraying run is only
 * visible if the misses are recorded too, and recording ONLY the hits would
 * make the log a list of valid email addresses.
 */
class RecordAuthenticationEvent
{
    public function handleLogin(Login $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        /*
         * Asked BEFORE the row for this sign-in is written, or the answer is
         * always "yes, they have signed in before" — this one.
         */
        $hasSignedInBefore = $this->hasSignedInBefore($user);
        $isNewDevice = $this->isNewDevice($user);

        $entry = $this->write(LoginOutcome::Success, (string) $user->email, $user, [
            // Filament's MFA runs before this fires for staff, so a successful
            // staff login has been through the second factor by definition.
            'was_two_factor_used' => $user->hasTwoFactorEnabled(),
            'is_new_device' => $isNewDevice,
        ]);

        $this->audit('auth.login', $user->isStaff()
            ? 'Signed in to the admin panel.'
            : 'Signed in.', $user);

        if (! $isNewDevice) {
            return;
        }

        /*
         * Worth its own audit entry rather than only a column.
         *
         * A sign-in from a device this account has never used before is the
         * single most useful early signal of a stolen password, and it is the
         * thing somebody scanning the trail should be able to filter on without
         * joining to another table.
         */
        $this->audit(
            'auth.login',
            'Signed in from a device this account has not used before.',
            $user,
        );

        /*
         * And tell the account holder, unless this is their first sign-in.
         *
         * On a brand new account every device is new, so the alert would fire
         * on the very first use and mean nothing — and an alert that fires when
         * it cannot mean anything is one people learn to dismiss, including on
         * the day it matters.
         *
         * Best effort: AccountNotifier swallows its own failures, because this
         * runs inside the sign-in path and an advisory must never be the reason
         * somebody cannot get in.
         */
        if ($hasSignedInBefore && $entry !== null) {
            app(AccountNotifier::class)->sendNewDeviceAlert($user, $entry);
        }
    }

    /**
     * Whether this account has ever completed a sign-in.
     *
     * Wrapped like every other query against this table: a broken login history
     * must not be able to stop somebody signing in. Returning false when it
     * cannot tell means no alert, which is the quiet direction — the audit
     * entry above is written either way.
     */
    private function hasSignedInBefore(User $user): bool
    {
        try {
            return LoginHistory::query()
                ->where('user_id', $user->getKey())
                ->where('outcome', LoginOutcome::Success)
                ->exists();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * A failed attempt.
     *
     * Note there is no attempt to distinguish "wrong password" from "no such
     * account" anywhere a user can see. The log records the address tried; the
     * response does not confirm whether it exists.
     */
    public function handleFailed(Failed $event): void
    {
        $email = (string) ($event->credentials['email'] ?? '');

        $this->write(
            LoginOutcome::Failed,
            $email,
            $event->user instanceof User ? $event->user : null,
        );

        $this->audit(
            'auth.login_failed',
            $email === ''
                ? 'A sign-in attempt failed.'
                : "A sign-in attempt for {$email} failed.",
            $event->user instanceof User ? $event->user : null,
        );
    }

    /**
     * Too many attempts.
     *
     * Recorded separately from a plain failure because the two mean different
     * things: failures happen to everybody, and a lockout is either an attack
     * or a member of staff who needs help within the next five minutes.
     */
    public function handleLockout(Lockout $event): void
    {
        $email = (string) $event->request->input('email', '');

        $this->write(LoginOutcome::LockedOut, $email, null);

        $this->audit(
            'auth.locked_out',
            $email === ''
                ? 'Sign-in attempts were locked out after repeated failures.'
                : "Sign-in for {$email} was locked out after repeated failures.",
        );
    }

    public function handleLogout(Logout $event): void
    {
        if ($event->user instanceof User) {
            $this->audit('auth.logout', 'Signed out.', $event->user);
        }
    }

    public function handlePasswordReset(PasswordReset $event): void
    {
        if ($event->user instanceof User) {
            $this->audit(
                'auth.password_reset',
                'Password was reset.',
                $event->user,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function write(LoginOutcome $outcome, string $email, ?User $user, array $extra = []): ?LoginHistory
    {
        try {
            return LoginHistory::create([
                'user_id' => $user?->getKey(),
                // Truncated rather than dropped: an over-long address is still
                // evidence of what was tried.
                'email_attempted' => Str::limit(mb_strtolower(trim($email)), 190, ''),
                'outcome' => $outcome,
                'ip_address' => $this->ip(),
                'user_agent' => Str::limit((string) $this->userAgent(), 1000, ''),
                'device_type' => $this->deviceType(),
                'platform' => $this->platform(),
                'browser' => $this->browser(),
                ...$extra,
            ]);
        } catch (Throwable) {
            /*
             * Never break a sign-in over its own logging.
             *
             * A member of staff who cannot log in because the login history
             * table is full is a worse outcome than a missing row — and the
             * audit trail, which is the accountability record, is written
             * separately and swallows its own failures the same way.
             */
        }

        return null;
    }

    private function audit(string $event, string $description, ?User $user = null): void
    {
        app(AuditLogger::class)->record($event, $description, causer: $user);
    }

    /**
     * Whether this account has been seen on this device before.
     *
     * Matched on the user agent, which is crude — two people on the same phone
     * model look identical. It is used only to say "this looks new, check it
     * was you", never to deny access, and for that a crude signal that
     * occasionally over-warns is the right trade.
     */
    private function isNewDevice(User $user): bool
    {
        $agent = $this->userAgent();

        /*
         * Nothing to match on, so no claim is made.
         *
         * Guarded on the user agent rather than on `runningInConsole()`. The
         * console check looked equivalent and was not: it also disabled device
         * detection under every test, so the whole path read as working while
         * being exercised by nothing. Asking whether there is actually a device
         * to recognise is both the honest question and the testable one.
         *
         * Returning false here means "not flagged as new", which is the right
         * direction: a warning that fires when it cannot tell is a warning
         * people learn to dismiss.
         */
        if ($agent === null || $agent === '') {
            return false;
        }

        try {
            return ! LoginHistory::query()
                ->where('user_id', $user->getKey())
                ->where('outcome', LoginOutcome::Success)
                ->where('user_agent', $agent)
                ->exists();
        } catch (Throwable) {
            /*
             * This is a query against the logging table, so it gets the same
             * protection the write does.
             *
             * It was outside that protection at first, which meant "logging
             * never breaks a sign-in" was true of the insert and false of the
             * lookup immediately before it — a broken table would still have
             * taken the login down, one line earlier than expected.
             */
            return false;
        }
    }

    private function ip(): ?string
    {
        return app()->runningInConsole() ? null : request()->ip();
    }

    /**
     * The user agent, or null when there is none.
     *
     * In a console context Laravel binds a synthetic request that carries no
     * user agent, so this returns null there without needing to ask whether it
     * is in a console — which is what makes the behaviour identical in
     * production and under test.
     */
    private function userAgent(): ?string
    {
        return app()->bound('request') ? request()->userAgent() : null;
    }

    /**
     * The coarse device class the request already announced.
     *
     * Three possible values from a string the browser sent unprompted — not a
     * fingerprint, and the same approach `CountVisit` takes for the same
     * reason. It is here so a person reading their own sign-in history
     * recognises "mobile, Android" as themselves.
     */
    private function deviceType(): ?string
    {
        $agent = Str::lower((string) $this->userAgent());

        return match (true) {
            $agent === '' => null,
            str_contains($agent, 'ipad'), str_contains($agent, 'tablet') => 'tablet',
            str_contains($agent, 'mobi'), str_contains($agent, 'android') => 'mobile',
            str_contains($agent, 'bot'), str_contains($agent, 'crawl') => 'bot',
            default => 'desktop',
        };
    }

    private function platform(): ?string
    {
        $agent = Str::lower((string) $this->userAgent());

        return match (true) {
            $agent === '' => null,
            str_contains($agent, 'android') => 'Android',
            str_contains($agent, 'iphone'), str_contains($agent, 'ipad') => 'iOS',
            str_contains($agent, 'windows') => 'Windows',
            str_contains($agent, 'mac os') => 'macOS',
            str_contains($agent, 'linux') => 'Linux',
            default => null,
        };
    }

    private function browser(): ?string
    {
        $agent = Str::lower((string) $this->userAgent());

        // Order matters: Edge and Opera both claim to be Chrome, and Chrome
        // claims to be Safari. Testing the impostors first is the only way to
        // get a truthful answer out of a user-agent string.
        return match (true) {
            $agent === '' => null,
            str_contains($agent, 'edg/') => 'Edge',
            str_contains($agent, 'opr/'), str_contains($agent, 'opera') => 'Opera',
            str_contains($agent, 'samsungbrowser') => 'Samsung Internet',
            str_contains($agent, 'firefox') => 'Firefox',
            str_contains($agent, 'chrome') => 'Chrome',
            str_contains($agent, 'safari') => 'Safari',
            default => null,
        };
    }
}
