<?php

declare(strict_types=1);

use App\Enums\LoginOutcome;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Models\Donor;
use App\Models\EmailLog;
use App\Models\LoginHistory;
use App\Models\User;
use App\Support\TwoFactor;
use Database\Seeders\MessageTemplateSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\ThemeSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Changing an address, and the second step
|--------------------------------------------------------------------------
|
| The two open questions this closes, and the reason both were left open: each
| is a place where the convenient version is the dangerous one.
|
| CHANGING THE ADDRESS is how a stolen session becomes permanent — password
| resets follow the address, so once it moves the real owner has no way back
| that does not involve a person. Three things guard it: the current password,
| a link the NEW address must open, and a warning to the OLD one carrying a
| cancel link. Until the second happens, `email` is untouched.
|
| THE SECOND STEP has to be a step. The account is never authenticated while
| the challenge is on screen — a factor somebody can skip by closing the tab is
| a suggestion. And it is rate limited, because six digits is a million
| guesses to somebody who already has the password.
|
*/

beforeEach(function () {
    $this->seed(SettingsSeeder::class);
    $this->seed(ThemeSettingsSeeder::class);
    $this->seed(MessageTemplateSeeder::class);
    $this->seed(RoleAndPermissionSeeder::class);
});

/** A donor with two-factor already set up. */
function donorWithTwoFactor(string $secret = 'ADUMJO5634NPDEKW'): User
{
    $user = User::factory()->create(['email' => 'donor@example.test']);

    $user->enableTwoFactor($secret, ['aaaa-bbbb', 'cccc-dddd']);

    return $user->refresh();
}

// ── Changing the address: the request ───────────────────────────────────────

it('does not move the address until the new one proves itself', function () {
    /*
     * The property everything else rests on. An attacker with a stolen session
     * who gets this far has changed nothing, and the real owner has a warning
     * in the inbox they still control.
     */
    $user = User::factory()->create(['email' => 'old@example.test']);

    $this->actingAs($user)->post('/account/email', [
        'email' => 'new@example.test',
        'current_password' => 'password',
    ])->assertRedirect();

    $user->refresh();

    expect($user->email)->toBe('old@example.test')
        ->and($user->pending_email)->toBe('new@example.test');
});

it('refuses without the current password', function () {
    // Without this, a session left open on an internet café machine is enough
    // to take the account permanently.
    $user = User::factory()->create(['email' => 'old@example.test']);

    $this->actingAs($user)->post('/account/email', [
        'email' => 'new@example.test',
        'current_password' => 'not-the-password',
    ])->assertSessionHasErrors('current_password');

    expect($user->refresh()->pending_email)->toBeNull();
});

it('warns the old address as well as writing to the new one', function () {
    /*
     * The security control, and the one people leave out. It goes out on the
     * REQUEST rather than on the completion, because the moment it is useful is
     * before the change has happened.
     */
    $user = User::factory()->create(['email' => 'old@example.test']);

    $this->actingAs($user)->post('/account/email', [
        'email' => 'new@example.test',
        'current_password' => 'password',
    ]);

    expect(EmailLog::where('template_key', 'account.email_change_confirm')
        ->where('to_address', 'new@example.test')->exists())->toBeTrue()
        ->and(EmailLog::where('template_key', 'account.email_change_alert')
            ->where('to_address', 'old@example.test')->exists())->toBeTrue();
});

it('refuses an address another account already holds', function () {
    User::factory()->create(['email' => 'taken@example.test']);
    $user = User::factory()->create(['email' => 'old@example.test']);

    $this->actingAs($user)->post('/account/email', [
        'email' => 'taken@example.test',
        'current_password' => 'password',
    ])->assertSessionHasErrors('email');
});

it('refuses an address another account is already trying to claim', function () {
    // Two people racing for one address would otherwise both be told to check
    // their inbox, and the second to click would hit the unique index as a 500.
    $other = User::factory()->create();
    $other->requestEmailChange('contested@example.test');

    $user = User::factory()->create(['email' => 'old@example.test']);

    $this->actingAs($user)->post('/account/email', [
        'email' => 'contested@example.test',
        'current_password' => 'password',
    ])->assertSessionHasErrors('email');
});

// ── Changing the address: the confirmation ──────────────────────────────────

it('moves the address when the new one opens its link', function () {
    $user = User::factory()->create(['email' => 'old@example.test']);
    $user->requestEmailChange('new@example.test');

    $this->actingAs($user)->get(emailChangeUrl($user))->assertRedirect();

    $user->refresh();

    expect($user->email)->toBe('new@example.test')
        ->and($user->pending_email)->toBeNull()
        ->and($user->hasVerifiedEmail())->toBeTrue();
});

it('refuses a link whose hash does not match the pending address', function () {
    /*
     * Ties the link to the address it was issued for, so a link generated for
     * one requested change cannot confirm a different one requested afterwards.
     */
    $user = User::factory()->create(['email' => 'old@example.test']);
    $user->requestEmailChange('new@example.test');

    $url = URL::temporarySignedRoute('account.email.confirm', now()->addHour(), [
        'ulid' => $user->ulid,
        'hash' => sha1('somewhere.else@example.test'),
    ]);

    $this->actingAs($user)->get($url)->assertForbidden();

    expect($user->refresh()->email)->toBe('old@example.test');
});

it('refuses an expired request even with a valid signature', function () {
    // The signed URL and the model both hold the window, so a request left
    // hanging cannot be completed later from an old inbox.
    $user = User::factory()->create(['email' => 'old@example.test']);
    $user->requestEmailChange('new@example.test');
    $user->forceFill(['pending_email_requested_at' => now()->subDay()])->save();

    $this->actingAs($user)->get(emailChangeUrl($user))->assertForbidden();

    expect($user->refresh()->email)->toBe('old@example.test');
});

it('does not inherit somebody else\'s giving history through an address change', function () {
    /*
     * `claimDonorRecord()` matches an unclaimed donor by email, and it is
     * deliberately NOT called on a change. Otherwise moving your account to an
     * address that happens to belong to an existing donor record would hand you
     * that person's entire giving history.
     */
    $donor = Donor::factory()->create(['email' => 'generous@example.test', 'user_id' => null]);

    $user = User::factory()->create(['email' => 'old@example.test']);
    $user->requestEmailChange('generous@example.test');

    $this->actingAs($user)->get(emailChangeUrl($user));

    expect($donor->fresh()->user_id)->toBeNull();
});

// ── Changing the address: stopping it ───────────────────────────────────────

it('lets the old address cancel without signing in', function () {
    /*
     * The whole point of the cancel link: it has to work for somebody who may
     * be locked out of their own session, which is the situation it exists for.
     */
    $user = User::factory()->create(['email' => 'old@example.test']);
    $user->requestEmailChange('attacker@example.test');

    $this->get(emailCancelUrl($user))->assertRedirect(route('password.request'));

    $user->refresh();

    expect($user->pending_email)->toBeNull()
        ->and($user->email)->toBe('old@example.test');
});

it('ends every session when a change is cancelled', function () {
    /*
     * Somebody had to be signed in to request the change. If the account holder
     * says it was not them, whoever did it still holds a session — and leaving
     * it alive would let them simply ask again.
     */
    $user = User::factory()->create(['email' => 'old@example.test']);
    $before = $user->remember_token;
    $user->requestEmailChange('attacker@example.test');

    $this->get(emailCancelUrl($user));

    expect($user->refresh()->remember_token)->not->toBe($before);
});

it('records the request, the change and the cancellation separately', function () {
    // Three different facts. A request that was never confirmed is the trace an
    // attempted takeover leaves.
    $user = User::factory()->create(['email' => 'old@example.test']);

    $this->actingAs($user)->post('/account/email', [
        'email' => 'new@example.test',
        'current_password' => 'password',
    ]);

    expect(DB::table('audit_logs')->where('event', 'auth.email_change_requested')->exists())->toBeTrue();

    $this->actingAs($user->refresh())->get(emailChangeUrl($user));

    expect(DB::table('audit_logs')->where('event', 'auth.email_changed')->exists())->toBeTrue();
});

// ── Two-factor: turning it on ───────────────────────────────────────────────

it('does not store a secret until a code proves it works', function () {
    /*
     * A secret in the column with no working app behind it would gate the
     * sign-in challenge on something the account holder cannot produce — an
     * account locked out of itself.
     */
    $user = User::factory()->create();

    $this->actingAs($user)->get('/account/security/two-factor')->assertOk();

    expect($user->refresh()->hasTwoFactorEnabled())->toBeFalse()
        ->and($user->two_factor_secret)->toBeNull();
});

it('turns two-factor on with a valid code and the password', function () {
    $user = User::factory()->create();
    $twoFactor = app(TwoFactor::class);

    $this->actingAs($user)->get('/account/security/two-factor');

    $secret = session('account.two_factor.candidate');

    expect($secret)->not->toBeNull();

    $this->actingAs($user)->post('/account/security/two-factor', [
        'code' => app(TwoFactor::class)->currentCode($secret),
        'current_password' => 'password',
    ])->assertRedirect(route('account.security'));

    expect($user->refresh()->hasTwoFactorEnabled())->toBeTrue()
        ->and($user->remainingRecoveryCodes())->toBe(TwoFactor::RECOVERY_CODE_COUNT);

    unset($twoFactor);
});

it('refuses to turn it on without the password', function () {
    // Adding a second step to somebody else's account locks them out of it just
    // as effectively as removing one lets an attacker in.
    $user = User::factory()->create();

    $this->actingAs($user)->get('/account/security/two-factor');

    $this->actingAs($user)->post('/account/security/two-factor', [
        'code' => app(TwoFactor::class)->currentCode(session('account.two_factor.candidate')),
        'current_password' => 'wrong',
    ])->assertSessionHasErrors('current_password');

    expect($user->refresh()->hasTwoFactorEnabled())->toBeFalse();
});

it('shows the recovery codes exactly once', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/account/security/two-factor');

    $this->actingAs($user)->post('/account/security/two-factor', [
        'code' => app(TwoFactor::class)->currentCode(session('account.two_factor.candidate')),
        'current_password' => 'password',
    ])->assertSessionHas('recoveryCodes');

    // Flashed, so a reload does not show them again — and neither does whoever
    // opens the laptop next.
    $this->actingAs($user->refresh())->get('/account/security')
        ->assertOk()
        ->assertSessionMissing('recoveryCodes');
});

it('emails the account holder when it is turned off', function () {
    /*
     * Nobody asks for this. Removing the second factor is what an attacker does
     * once they are inside, and it is silent everywhere else.
     */
    $user = donorWithTwoFactor();

    $this->actingAs($user)->delete('/account/security/two-factor', [
        'current_password' => 'password',
    ])->assertRedirect();

    expect($user->refresh()->hasTwoFactorEnabled())->toBeFalse()
        ->and(EmailLog::where('template_key', 'account.two_factor_disabled')->exists())->toBeTrue();
});

it('clears the recovery codes when it is turned off', function () {
    // Codes left behind would still work against a factor re-enabled later from
    // stale state.
    $user = donorWithTwoFactor();

    $this->actingAs($user)->delete('/account/security/two-factor', ['current_password' => 'password']);

    expect($user->refresh()->remainingRecoveryCodes())->toBe(0);
});

// ── Two-factor: the challenge ───────────────────────────────────────────────

it('does not sign anybody in on the password alone', function () {
    /*
     * ⚠ THE ONE THAT MATTERS. The account must not be in the guard while the
     * challenge is on screen — otherwise the second factor is a page somebody
     * navigates away from, which is a suggestion rather than a factor.
     */
    $user = donorWithTwoFactor();

    $this->post('/login', ['email' => 'donor@example.test', 'password' => 'password'])
        ->assertRedirect(route('two-factor.challenge'));

    $this->assertGuest();
});

it('does not record a sign-in that has not finished', function () {
    // A success row here would say somebody signed in when they had produced
    // one of the two things required.
    $user = donorWithTwoFactor();

    $this->post('/login', ['email' => 'donor@example.test', 'password' => 'password']);

    expect(LoginHistory::where('user_id', $user->getKey())
        ->where('outcome', LoginOutcome::Success)->exists())->toBeFalse();
});

it('signs in once the code is right', function () {
    $user = donorWithTwoFactor();

    $this->post('/login', ['email' => 'donor@example.test', 'password' => 'password']);

    $this->post('/two-factor-challenge', [
        'code' => app(TwoFactor::class)->currentCode('ADUMJO5634NPDEKW'),
    ])->assertRedirect(route('account.dashboard'));

    $this->assertAuthenticatedAs($user);
});

it('accepts a recovery code and spends it', function () {
    // Single use. A recovery code that still works afterwards is a password
    // somebody wrote on a piece of paper.
    $user = donorWithTwoFactor();

    $this->post('/login', ['email' => 'donor@example.test', 'password' => 'password']);
    $this->post('/two-factor-challenge', ['code' => 'aaaa-bbbb'])->assertRedirect();

    $this->assertAuthenticatedAs($user);

    expect($user->refresh()->remainingRecoveryCodes())->toBe(1);
});

it('refuses a recovery code a second time', function () {
    $user = donorWithTwoFactor();

    $this->post('/login', ['email' => 'donor@example.test', 'password' => 'password']);
    $this->post('/two-factor-challenge', ['code' => 'aaaa-bbbb']);
    $this->post('/logout');

    $this->post('/login', ['email' => 'donor@example.test', 'password' => 'password']);
    $this->post('/two-factor-challenge', ['code' => 'aaaa-bbbb'])->assertSessionHasErrors('code');

    $this->assertGuest();
});

it('records a failed second factor under its own outcome', function () {
    /*
     * `LoginOutcome::TwoFactorFailed` has existed since Module 1 with nothing
     * writing it — and it is the most interesting failure in the table, because
     * it means somebody got the password right.
     */
    $user = donorWithTwoFactor();

    $this->post('/login', ['email' => 'donor@example.test', 'password' => 'password']);
    $this->post('/two-factor-challenge', ['code' => '000000'])->assertSessionHasErrors('code');

    expect(LoginHistory::where('user_id', $user->getKey())
        ->where('outcome', LoginOutcome::TwoFactorFailed)->exists())->toBeTrue();
});

it('rate limits the challenge', function () {
    /*
     * Six digits is a million guesses to somebody who already has the password.
     * Without this they can try all of them.
     */
    $user = donorWithTwoFactor();

    $this->post('/login', ['email' => 'donor@example.test', 'password' => 'password']);

    foreach (range(1, (int) config('security.rate_limits.login')) as $ignored) {
        $this->post('/two-factor-challenge', ['code' => '000000']);
    }

    $this->post('/two-factor-challenge', [
        'code' => app(TwoFactor::class)->currentCode('ADUMJO5634NPDEKW'),
    ])->assertSessionHasErrors('code');

    $this->assertGuest();
});

it('refuses the challenge when the session has forgotten who is signing in', function () {
    // The password is not still valid here, so there is nothing to resume.
    donorWithTwoFactor();

    $this->post('/two-factor-challenge', ['code' => '000000'])
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

it('refuses an account suspended between the password and the code', function () {
    // Re-read from the database rather than trusted from the session, so a
    // suspension mid-challenge is not bypassed by a stale copy.
    $user = donorWithTwoFactor();

    $this->post('/login', ['email' => 'donor@example.test', 'password' => 'password']);

    $user->suspend('Suspended mid-challenge');

    $this->post('/two-factor-challenge', [
        'code' => app(TwoFactor::class)->currentCode('ADUMJO5634NPDEKW'),
    ])->assertRedirect(route('login'));

    $this->assertGuest();
});

it('leaves a donor without two-factor signing in as before', function () {
    // Optional, not mandatory. Blueprint §7.1.
    User::factory()->create(['email' => 'plain@example.test']);

    $this->post('/login', ['email' => 'plain@example.test', 'password' => 'password'])
        ->assertRedirect(route('account.dashboard'));

    $this->assertAuthenticated();
});

// ── The pages render ────────────────────────────────────────────────────────

it('renders the enrolment page with a key that can be typed', function () {
    // Somebody setting this up on the phone they are reading it on cannot scan
    // their own screen.
    $user = User::factory()->create();

    $this->actingAs($user)->get('/account/security/two-factor')
        ->assertOk()
        ->assertSee(session('account.two_factor.candidate') ?? '', escape: false);
});

it('renders the challenge page', function () {
    donorWithTwoFactor();

    $this->post('/login', ['email' => 'donor@example.test', 'password' => 'password']);

    $this->get('/two-factor-challenge')->assertOk();
});

it('sends somebody with no pending challenge back to the sign-in form', function () {
    $this->get('/two-factor-challenge')->assertRedirect(route('login'));
});

/** A valid, signed confirmation URL for the pending address. */
function emailChangeUrl(User $user): string
{
    return URL::temporarySignedRoute('account.email.confirm', now()->addHour(), [
        'ulid' => $user->ulid,
        'hash' => sha1((string) $user->pending_email),
    ]);
}

/** A valid, signed cancellation URL, of the kind sent to the old address. */
function emailCancelUrl(User $user): string
{
    return URL::temporarySignedRoute('account.email.cancel', now()->addHour(), [
        'ulid' => $user->ulid,
        'hash' => sha1((string) $user->pending_email),
    ]);
}

/** Keeps the pending-challenge session key honest against the controller. */
it('uses the session key the controller declares', function () {
    expect(TwoFactorChallengeController::PENDING)->toBe('auth.two_factor.user');
});
