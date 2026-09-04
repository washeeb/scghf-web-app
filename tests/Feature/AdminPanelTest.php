<?php

declare(strict_types=1);

use App\Enums\LoginOutcome;
use App\Models\AuditLog;
use App\Models\LoginHistory;
use App\Models\User;
use Database\Seeders\SettingsSeeder;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The admin panel's front door
|--------------------------------------------------------------------------
|
| An administrator here can read beneficiary case files, export donor records
| and approve refunds. A password is the credential most likely to have been
| reused somewhere already breached, so the second factor is not a feature —
| it is the thing standing between a leaked password and every record the
| foundation holds.
|
| The other half of this file is about the records that were built and never
| written to: `login_histories` since Module 1, and the `auth.*` audit events
| since Module 8.
|
*/

// ── Where the panel lives ───────────────────────────────────────────────────

it('puts the panel where the configuration says, not at a hardcoded path', function () {
    // /admin is the first path a scanner tries; /administrator is the second.
    expect(config('admin.path'))->toBe('admin')
        ->and(route('filament.admin.auth.login'))->toContain('/admin/login');
});

it('reads the admin env keys that were documented and ignored since Phase 2', function () {
    expect(config('admin.require_two_factor'))->toBeTrue()
        ->and(config('admin.session_timeout'))->toBeGreaterThan(0)
        ->and(config('security.rate_limits.login'))->toBe(5);
});

it('takes its branding from the CMS rather than hardcoding it', function () {
    // CLAUDE.md's rule applies to the admin panel too: renaming the foundation
    // is an edit in the panel, not a deploy.
    $this->seed(SettingsSeeder::class);

    expect(filament()->getPanel('admin')->getBrandName())
        ->toBe(setting('general.short_name'));
});

// ── Who may open it ─────────────────────────────────────────────────────────

it('turns away somebody who is not signed in', function () {
    $this->get('/admin')->assertRedirect();
});

it('turns away a donor', function () {
    // Capability inside the panel is a permission question. Getting through the
    // door at all is a question of being staff.
    expect(User::factory()->donor()->create()->canAccessPanel())->toBeFalse();
});

it('turns away a suspended member of staff', function () {
    $user = User::factory()->staff()->suspended('Under review.')->create();

    expect($user->canAccessPanel())->toBeFalse();
});

it('turns away a deactivated account', function () {
    $user = User::factory()->staff()->create(['is_active' => false]);

    expect($user->canAccessPanel())->toBeFalse();
});

it('lets active staff in', function () {
    expect(User::factory()->staff()->create()->canAccessPanel())->toBeTrue();
});

// ── Two-factor ──────────────────────────────────────────────────────────────

it('requires a second factor for staff', function () {
    // Required, not offered. Blueprint §7.1.
    $user = User::factory()->staff()->create();

    expect($user->mustEnrolInTwoFactor())->toBeTrue()
        ->and(filament()->getPanel('admin')->hasMultiFactorAuthentication())->toBeTrue();
});

it('stores the TOTP secret in the columns Module 1 already built', function () {
    // Wired to the existing encrypted columns rather than a second set of
    // Filament's own.
    $user = User::factory()->staff()->create();

    $user->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');

    expect($user->fresh()->getAppAuthenticationSecret())->toBe('JBSWY3DPEHPK3PXP')
        ->and($user->fresh()->two_factor_secret)->toBe('JBSWY3DPEHPK3PXP');
});

it('confirms 2FA at the moment the secret is stored', function () {
    /*
     * The two must never disagree. A secret with no confirmation reads as
     * "enrolment started and abandoned", and `hasTwoFactorEnabled()` — which
     * the enrolment gate relies on — would be false for somebody whose 2FA
     * actually works.
     */
    $user = User::factory()->staff()->create();

    $user->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');

    expect($user->fresh()->hasTwoFactorEnabled())->toBeTrue()
        ->and($user->fresh()->mustEnrolInTwoFactor())->toBeFalse();
});

it('clears the confirmation when 2FA is switched off', function () {
    $user = User::factory()->staff()->create();
    $user->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');

    $user->saveAppAuthenticationSecret(null);

    expect($user->fresh()->hasTwoFactorEnabled())->toBeFalse()
        ->and($user->fresh()->two_factor_confirmed_at)->toBeNull();
});

it('never lets the secret out in a serialised model', function () {
    // Not into a response, a log line, or a queued job payload.
    $user = User::factory()->staff()->create();
    $user->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');

    $json = $user->fresh()->toArray();

    expect($json)->not->toHaveKey('two_factor_secret')
        ->and($json)->not->toHaveKey('two_factor_recovery_codes');
});

it('labels the authenticator entry with the email, not the name', function () {
    /*
     * Staff frequently hold more than one account — their own and a shared
     * finance login — and two entries reading "Ama Mensah" in an authenticator
     * app is a code typed from the wrong one at the worst moment.
     */
    $user = User::factory()->staff()->create(['name' => 'Ama Mensah', 'email' => 'ama@example.com']);

    expect($user->getAppAuthenticationHolderName())->toBe('ama@example.com');
});

it('keeps recovery codes, because the alternative is disabling 2FA over the phone', function () {
    $user = User::factory()->staff()->create();

    $user->saveAppAuthenticationRecoveryCodes(['aaa-bbb', 'ccc-ddd']);

    expect($user->fresh()->getAppAuthenticationRecoveryCodes())->toBe(['aaa-bbb', 'ccc-ddd']);
});

// ── The records that had no writer ──────────────────────────────────────────

it('records a successful sign-in in both places', function () {
    $user = User::factory()->staff()->create();

    $this->actingAs($user);
    Event::dispatch(new Login('web', $user, false));

    $history = LoginHistory::first();

    expect($history)->not->toBeNull()
        ->and($history->outcome)->toBe(LoginOutcome::Success)
        ->and($history->user_id)->toBe($user->id)
        ->and(AuditLog::where('event', 'auth.login')->exists())->toBeTrue();
});

it('records a failed attempt without confirming the account exists', function () {
    /*
     * The address tried is stored on every failure, including for addresses
     * with no account. A spraying run is only visible if the misses are
     * recorded — and recording only the hits would make the log a list of
     * valid email addresses.
     */
    Event::dispatch(new Failed('web', null, ['email' => 'nobody@example.com']));

    $history = LoginHistory::first();

    expect($history->outcome)->toBe(LoginOutcome::Failed)
        ->and($history->email_attempted)->toBe('nobody@example.com')
        ->and($history->user_id)->toBeNull();
});

it('records a lockout separately from a plain failure', function () {
    // Failures happen to everybody. A lockout is either an attack or a member
    // of staff who needs help in the next five minutes.
    Event::dispatch(new Lockout(Request::create('/admin/login', 'POST', ['email' => 'ama@example.com'])));

    expect(LoginHistory::first()->outcome)->toBe(LoginOutcome::LockedOut)
        ->and(AuditLog::where('event', 'auth.locked_out')->exists())->toBeTrue();
});

it('flags a sign-in from a device the account has not used before', function () {
    // The most useful early signal of a stolen password.
    $user = User::factory()->staff()->create();
    $this->actingAs($user);
    request()->headers->set('User-Agent', 'Mozilla/5.0 (Linux; Android 10) Chrome/120');

    Event::dispatch(new Login('web', $user, false));

    expect(LoginHistory::first()->is_new_device)->toBeTrue()
        ->and(LoginHistory::first()->device_type)->toBe('mobile')
        ->and(LoginHistory::first()->browser)->toBe('Chrome');
});

it('does not claim a device is new when it cannot tell', function () {
    /*
     * A console sign-in, or a request with no user agent, gives nothing to
     * match on. Claiming "new device" there would fire a suspicious-login
     * warning at somebody who did nothing unusual — and a warning that cries
     * wolf is a warning people learn to dismiss.
     */
    $user = User::factory()->staff()->create();
    $this->actingAs($user);
    request()->headers->remove('User-Agent');

    Event::dispatch(new Login('web', $user, false));

    expect(LoginHistory::first()->is_new_device)->toBeFalse();
});

it('recognises a device the account has used before', function () {
    $user = User::factory()->staff()->create();
    $this->actingAs($user);
    request()->headers->set('User-Agent', 'Mozilla/5.0 (Linux; Android 10) Chrome/120');

    Event::dispatch(new Login('web', $user, false));
    Event::dispatch(new Login('web', $user, false));

    expect(LoginHistory::latest('id')->first()->is_new_device)->toBeFalse();
});

it('never breaks a sign-in over its own logging', function () {
    // A member of staff who cannot log in because a logging table is full is a
    // worse outcome than a missing row.
    $user = User::factory()->staff()->create();
    Schema::drop('login_histories');

    Event::dispatch(new Login('web', $user, false));
})->throwsNoExceptions();

it('records a password reset, because it is how a compromise is ended', function () {
    $user = User::factory()->staff()->create();

    Event::dispatch(new PasswordReset($user));

    expect(AuditLog::where('event', 'auth.password_reset')->exists())->toBeTrue();
});
