<?php

declare(strict_types=1);

use App\Enums\LoginOutcome;
use App\Enums\UserType;
use App\Models\Donation;
use App\Models\Donor;
use App\Models\EmailLog;
use App\Models\LoginHistory;
use App\Models\Suppression;
use App\Models\User;
use Database\Seeders\MessageTemplateSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\ThemeSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Spatie\Honeypot\EncryptedTime;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Public donor accounts
|--------------------------------------------------------------------------
|
| Four things this file exists to hold in place.
|
| STAFF CANNOT SIGN IN HERE. Two-factor is mandatory for staff and it is
| enforced inside Filament's login flow. A public form that authenticated a
| staff account would hand them a fully authenticated session having shown one
| factor — and `canAccessPanel()` would then let them walk into the admin panel
| past the check, with nothing visibly wrong. This is the most important test in
| the file.
|
| THE FORM NEVER SAYS WHETHER AN ADDRESS HAS AN ACCOUNT. Not on login, not on
| password reset. Otherwise the forgotten-password form is a free service for
| enumerating the foundation's donors.
|
| EVERY MESSAGE GOES THROUGH THE DISPATCHER. Laravel's default notifications
| would be a second way out — one with no suppression check, no email_logs row
| and no share of the host's hourly cap.
|
| VERIFICATION IS WHAT EARNS THE GIVING HISTORY. `donors` is matched on email
| address, so attaching it at registration would let anybody who types a known
| donor's address read what that person has given.
|
*/

beforeEach(function () {
    $this->seed(SettingsSeeder::class);
    $this->seed(ThemeSettingsSeeder::class);
    $this->seed(MessageTemplateSeeder::class);
    $this->seed(RoleAndPermissionSeeder::class);

    RateLimiter::clear('login|donor@example.test|127.0.0.1');
});

/** The fields a valid registration posts. */
function registrationPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Ama Mensah',
        'email' => 'ama@example.test',
        'phone' => '024 123 4567',
        'password' => 'a-long-enough-passphrase',
        'password_confirmation' => 'a-long-enough-passphrase',
        'accepts_privacy_policy' => '1',
    ], $overrides);
}

// ── Registration ────────────────────────────────────────────────────────────

it('creates a donor account and signs it in', function () {
    $this->post('/register', registrationPayload())
        ->assertRedirect(route('verification.notice'));

    $user = User::where('email', 'ama@example.test')->firstOrFail();

    expect($user->type)->toBe(UserType::Donor)
        ->and($user->hasRole('Donor'))->toBeTrue()
        ->and($user->hasVerifiedEmail())->toBeFalse();

    $this->assertAuthenticatedAs($user);
});

it('never creates a staff account from the public form', function () {
    /*
     * `type` is not in the form, but it IS in $fillable — so a crafted request
     * posting type=staff would mass-assign it if the controller passed the
     * request through. A staff account created this way would hold no 2FA and
     * would pass canAccessPanel().
     */
    $this->post('/register', registrationPayload(['type' => 'staff']));

    expect(User::where('email', 'ama@example.test')->value('type'))->toBe(UserType::Donor);
});

it('hashes the password rather than storing it', function () {
    $this->post('/register', registrationPayload());

    $stored = (string) User::where('email', 'ama@example.test')->value('password');

    expect($stored)->not->toBe('a-long-enough-passphrase')
        ->and(Hash::check('a-long-enough-passphrase', $stored))->toBeTrue();
});

it('refuses a password shorter than the policy', function () {
    $this->post('/register', registrationPayload([
        'password' => 'short',
        'password_confirmation' => 'short',
    ]))->assertSessionHasErrors('password');

    expect(User::count())->toBe(0);
});

it('refuses a registration that has not accepted the privacy notice', function () {
    // Not a formality. Without it there is no lawful basis under Act 843 for
    // processing anything this form collects.
    $this->post('/register', registrationPayload(['accepts_privacy_policy' => '0']))
        ->assertSessionHasErrors('accepts_privacy_policy');

    expect(User::count())->toBe(0);
});

it('leaves marketing off when the boxes are not ticked', function () {
    $this->post('/register', registrationPayload());

    $user = User::where('email', 'ama@example.test')->firstOrFail();

    expect($user->accepts_email_marketing)->toBeFalse()
        ->and($user->accepts_sms_marketing)->toBeFalse()
        // No consent given, so no consent timestamp. A date here would be
        // evidence of something that did not happen.
        ->and($user->marketing_consent_at)->toBeNull();
});

it('records when marketing consent was given', function () {
    $this->post('/register', registrationPayload(['accepts_email_marketing' => '1']));

    $user = User::where('email', 'ama@example.test')->firstOrFail();

    expect($user->accepts_email_marketing)->toBeTrue()
        ->and($user->marketing_consent_at)->not->toBeNull();
});

it('refuses a second account on one address', function () {
    User::factory()->create(['email' => 'ama@example.test']);

    $this->post('/register', registrationPayload())->assertSessionHasErrors('email');
});

it('refuses an address belonging to a soft-deleted account', function () {
    /*
     * The unique index on `users.email` does not exclude soft-deleted rows, so
     * a validation rule that DID exclude them would turn a friendly message
     * into a database error on insert.
     */
    $user = User::factory()->create(['email' => 'ama@example.test']);
    $user->delete();

    $this->post('/register', registrationPayload())->assertSessionHasErrors('email');
});

it('closes the form entirely when registration is switched off', function () {
    // A 404 rather than a 403: a 403 announces there is something here worth
    // coming back for, which defeats closing it during a spam wave.
    config()->set('security.accounts.registration_open', false);

    $this->get('/register')->assertNotFound();
    $this->post('/register', registrationPayload())->assertNotFound();

    expect(User::count())->toBe(0);
});

it('normalises a Ghanaian number typed any of the usual ways', function (string $typed) {
    $this->post('/register', registrationPayload(['phone' => $typed]));

    expect(User::where('email', 'ama@example.test')->value('phone'))->toBe('+233241234567');
})->with(['024 123 4567', '0241234567', '+233241234567', '233241234567']);

// ── The honeypot ────────────────────────────────────────────────────────────
//
// Worth testing rather than trusting, because the failure mode is silent in
// both directions: a broken honeypot lets every bot through and nothing says
// so, and an over-eager one silently discards real registrations. The package
// answers spam with a blank page rather than an error, which is deliberate —
// an error tells the bot's author what to fix.

it('discards a registration with the honeypot field filled in', function () {
    /*
     * Only something reading the HTML fills a field that is hidden from
     * people. `HONEYPOT_RANDOMIZE` appends a random suffix to the name, so
     * the check matches on the prefix and a bot cannot learn the name once.
     */
    $field = (string) config('honeypot.name_field_name');

    $this->post('/register', registrationPayload([
        $field => 'a bot filled this in',
        (string) config('honeypot.valid_from_field_name') => (string) EncryptedTime::create(now()->subMinute()),
    ]));

    expect(User::count())->toBe(0);
});

it('discards a registration that arrives before the form could have been filled', function () {
    // A timestamp still in the future means the form was submitted faster than
    // it was rendered, which no person does.
    $this->post('/register', registrationPayload([
        (string) config('honeypot.name_field_name') => '',
        (string) config('honeypot.valid_from_field_name') => (string) EncryptedTime::create(now()->addMinute()),
    ]));

    expect(User::count())->toBe(0);
});

it('puts the honeypot on the form rather than only on the route', function () {
    /*
     * The middleware only checks a form that carries the fields —
     * `honeypot_fields_required_for_all_forms` is false, so a request with
     * neither field passes straight through. A route protected by middleware
     * whose form forgot `<x-honeypot />` is protection that does nothing.
     */
    $this->get('/register')
        ->assertOk()
        ->assertSee((string) config('honeypot.name_field_name'), escape: false)
        ->assertSee((string) config('honeypot.valid_from_field_name'), escape: false);
});

// ── Email verification ──────────────────────────────────────────────────────

it('sends the verification email through the dispatcher, not a notification', function () {
    /*
     * The row in `email_logs` is the proof. Laravel's default
     * `sendEmailVerificationNotification()` posts straight to the mail channel
     * and writes nothing here — which means no suppression check and no share
     * of the host's hourly cap.
     */
    $this->post('/register', registrationPayload());

    expect(EmailLog::where('template_key', 'account.verify_email')->count())->toBe(1)
        ->and(EmailLog::where('template_key', 'account.verify_email')->value('to_address'))
        ->toBe('ama@example.test');
});

it('verifies an address from a signed link', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->get(verificationUrlFor($user))
        ->assertRedirect(route('account.dashboard'));

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('refuses a verification link whose signature has been tampered with', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->get(verificationUrlFor($user).'&extra=1')
        ->assertForbidden();

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('refuses a link whose hash does not match the current address', function () {
    /*
     * The hash ties the link to the address the account had when it was issued.
     * Without it, a link generated before an address change would verify the
     * new one — which is how an attacker who changed the address confirms it.
     */
    $user = User::factory()->unverified()->create();

    $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
        'ulid' => $user->ulid,
        'hash' => sha1('somebody.else@example.test'),
    ]);

    $this->actingAs($user)->get($url)->assertForbidden();

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('lets an expired link be replaced rather than stranding the account', function () {
    $user = User::factory()->unverified()->create();

    $url = URL::temporarySignedRoute('verification.verify', now()->subMinute(), [
        'ulid' => $user->ulid,
        'hash' => sha1($user->email),
    ]);

    $this->actingAs($user)->get($url)->assertForbidden();

    $this->actingAs($user)->post(route('verification.send'))->assertRedirect();

    expect(EmailLog::where('template_key', 'account.verify_email')->count())->toBe(1);
});

it('opens the link without being signed in, because the phone is not the laptop', function () {
    /*
     * Somebody who registers on a phone and opens the link on a laptop is not
     * signed in there. Bouncing them to a login form at that moment loses a
     * good share of them — the signature is the authentication.
     */
    $user = User::factory()->unverified()->create();

    $this->get(verificationUrlFor($user))->assertRedirect(route('login'));

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    $this->assertGuest();
});

it('keeps the giving history hidden until the address is confirmed', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->get('/account')->assertRedirect(route('verification.notice'));
});

// ── Claiming the giving record ──────────────────────────────────────────────

it('attaches the giving record only once the address is proved', function () {
    /*
     * The whole reason verification gates the dashboard. `donors` is matched on
     * email, so attaching at registration would let anybody who types a known
     * donor's address read what that person has given, and to what.
     */
    $donor = Donor::factory()->create(['email' => 'ama@example.test', 'user_id' => null]);

    $this->post('/register', registrationPayload());

    expect($donor->fresh()->user_id)->toBeNull();

    $user = User::where('email', 'ama@example.test')->firstOrFail();
    $this->actingAs($user)->get(verificationUrlFor($user));

    expect($donor->fresh()->user_id)->toBe($user->getKey());
});

it('refuses to steal a giving record already claimed by another account', function () {
    // Two accounts on one address is a merge decision for staff, not something
    // to resolve silently inside a request.
    $incumbent = User::factory()->create();
    $donor = Donor::factory()->create([
        'email' => 'ama@example.test',
        'user_id' => $incumbent->getKey(),
    ]);

    $newcomer = User::factory()->unverified()->create(['email' => 'ama@example.test2']);
    $newcomer->forceFill(['email' => 'ama@example.test'])->saveQuietly();
    $newcomer->markEmailAsVerified();

    $newcomer->claimDonorRecord();

    expect($donor->fresh()->user_id)->toBe($incumbent->getKey());
});

it('creates a giving record for a donor who has never given', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->get(verificationUrlFor($user));

    expect($user->fresh()->donor)->not->toBeNull();
});

// ── Signing in ──────────────────────────────────────────────────────────────

it('signs a donor in', function () {
    $user = User::factory()->create(['email' => 'donor@example.test']);

    $this->post('/login', ['email' => 'donor@example.test', 'password' => 'password'])
        ->assertRedirect(route('account.dashboard'));

    $this->assertAuthenticatedAs($user);
});

it('refuses to sign a staff account in at the public form', function () {
    /*
     * ⚠ THE ONE THAT MATTERS.
     *
     * 2FA is mandatory for staff and enforced inside Filament's login flow. A
     * public form that authenticated this account would produce a fully
     * authenticated session having shown one factor — and canAccessPanel()
     * would then let it into the admin panel, past the check, with nothing
     * visibly wrong anywhere.
     */
    User::factory()->staff()->create(['email' => 'staff@example.test']);

    $this->post('/login', ['email' => 'staff@example.test', 'password' => 'password'])
        ->assertRedirect(filament()->getPanel('admin')->getLoginUrl());

    $this->assertGuest();
});

it('does not reveal that an address is a staff address to somebody without the password', function () {
    User::factory()->staff()->create(['email' => 'staff@example.test']);

    $this->post('/login', ['email' => 'staff@example.test', 'password' => 'wrong'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('gives the same message whether the address is unknown or the password is wrong', function () {
    /*
     * Both must be the framework's standard `auth.failed`. A different message
     * for "no such account" is how a login form becomes a way to ask which
     * addresses belong to donors of this foundation.
     */
    User::factory()->create(['email' => 'donor@example.test']);

    $this->post('/login', ['email' => 'nobody@example.test', 'password' => 'wrong'])
        ->assertSessionHasErrors(['email' => __('auth.failed')]);

    session()->forget('errors');

    $this->post('/login', ['email' => 'donor@example.test', 'password' => 'wrong'])
        ->assertSessionHasErrors(['email' => __('auth.failed')]);
});

it('refuses a suspended account and says so, once the password is right', function () {
    $user = User::factory()->create(['email' => 'donor@example.test']);
    $user->suspend('Testing');

    $this->post('/login', ['email' => 'donor@example.test', 'password' => 'password'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('charges a suspended account an attempt, so it is not a free password oracle', function () {
    $user = User::factory()->create(['email' => 'donor@example.test']);
    $user->suspend('Testing');

    $this->post('/login', ['email' => 'donor@example.test', 'password' => 'password']);

    expect(RateLimiter::attempts('login|donor@example.test|127.0.0.1'))->toBe(1);
});

it('regenerates the session on sign-in', function () {
    // Session fixation: an id an attacker planted before sign-in must not still
    // be the id afterwards.
    $user = User::factory()->create(['email' => 'donor@example.test']);

    $before = session()->getId();

    $this->post('/login', ['email' => 'donor@example.test', 'password' => 'password']);

    expect(session()->getId())->not->toBe($before);
});

it('locks out after too many failures and records why', function () {
    User::factory()->create(['email' => 'donor@example.test']);

    foreach (range(1, (int) config('security.rate_limits.login')) as $ignored) {
        $this->post('/login', ['email' => 'donor@example.test', 'password' => 'wrong']);
    }

    $this->post('/login', ['email' => 'donor@example.test', 'password' => 'password'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();

    // The lockout is the interesting row: it is either an attack or somebody
    // who needs help in the next five minutes.
    expect(LoginHistory::where('outcome', LoginOutcome::LockedOut)->exists())->toBeTrue();
});

it('cannot be used to lock a named donor out from another address', function () {
    /*
     * The narrow bucket is address + IP. Keyed on the address alone, anybody
     * could deny a named donor their own account for fifteen minutes at no
     * cost — a denial of service that looks exactly like the control working.
     */
    User::factory()->create(['email' => 'donor@example.test']);

    foreach (range(1, 5) as $ignored) {
        $this->post('/login', ['email' => 'donor@example.test', 'password' => 'wrong']);
    }

    RateLimiter::clear('login|donor@example.test|127.0.0.1');

    $this->post('/login', ['email' => 'donor@example.test', 'password' => 'password'])
        ->assertRedirect(route('account.dashboard'));
});

it('records a successful sign-in in the history', function () {
    $user = User::factory()->create(['email' => 'donor@example.test']);

    $this->post('/login', ['email' => 'donor@example.test', 'password' => 'password']);

    expect(LoginHistory::where('user_id', $user->getKey())
        ->where('outcome', LoginOutcome::Success)
        ->exists())->toBeTrue();
});

it('signs out with a POST and clears the session', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/logout')->assertRedirect(route('home'));

    $this->assertGuest();
});

// ── The new-device alert ────────────────────────────────────────────────────

it('does not warn about a new device on the first ever sign-in', function () {
    /*
     * Every device is new on a brand new account, so the alert would fire when
     * it cannot mean anything — and an alert that fires when it cannot mean
     * anything is one people learn to dismiss, including on the day it matters.
     */
    User::factory()->create(['email' => 'donor@example.test']);

    $this->withHeader('User-Agent', 'Mozilla/5.0 (Linux; Android 10)')
        ->post('/login', ['email' => 'donor@example.test', 'password' => 'password']);

    expect(EmailLog::where('template_key', 'account.new_device')->count())->toBe(0);
});

it('warns about a device the account has not been used on before', function () {
    $user = User::factory()->create(['email' => 'donor@example.test']);

    $this->withHeader('User-Agent', 'Mozilla/5.0 (Linux; Android 10)')
        ->post('/login', ['email' => 'donor@example.test', 'password' => 'password']);

    $this->post('/logout');

    $this->withHeader('User-Agent', 'Mozilla/5.0 (Macintosh; Intel Mac OS X)')
        ->post('/login', ['email' => 'donor@example.test', 'password' => 'password']);

    expect(EmailLog::where('template_key', 'account.new_device')
        ->where('to_address', 'donor@example.test')
        ->count())->toBe(1);

    unset($user);
});

// ── Password reset ──────────────────────────────────────────────────────────

it('does not say whether the address has an account', function () {
    /*
     * The whole security property of this form. Without it, it is a free
     * service for testing whether an address has an account here — which for a
     * foundation means confirming somebody is a donor.
     */
    User::factory()->create(['email' => 'donor@example.test']);

    $known = $this->post('/forgot-password', ['email' => 'donor@example.test']);
    $unknown = $this->post('/forgot-password', ['email' => 'nobody@example.test']);

    expect($known->getSession()->get('status'))->toBe($unknown->getSession()->get('status'));
});

it('sends the reset email through the dispatcher', function () {
    User::factory()->create(['email' => 'donor@example.test']);

    $this->post('/forgot-password', ['email' => 'donor@example.test']);

    expect(EmailLog::where('template_key', 'account.password_reset')->count())->toBe(1);
});

it('sends nothing at all for an address with no account', function () {
    $this->post('/forgot-password', ['email' => 'nobody@example.test']);

    expect(EmailLog::count())->toBe(0);
});

it('will not reset a staff password from the public form', function () {
    // Somebody with access to a staff inbox must not be able to set a password
    // and then use it — and the public form is exactly where they would try.
    User::factory()->staff()->create(['email' => 'staff@example.test']);

    $this->post('/forgot-password', ['email' => 'staff@example.test']);

    expect(EmailLog::count())->toBe(0);
});

it('changes the password from a valid reset token', function () {
    $user = User::factory()->create(['email' => 'donor@example.test']);

    $token = app('auth.password.broker')->createToken($user);

    $this->post('/reset-password', [
        'token' => $token,
        'email' => 'donor@example.test',
        'password' => 'a-brand-new-passphrase',
        'password_confirmation' => 'a-brand-new-passphrase',
    ])->assertRedirect(route('login'));

    expect(Hash::check('a-brand-new-passphrase', (string) $user->fresh()->password))->toBeTrue();
});

it('refuses a reset token that belongs to somebody else', function () {
    $user = User::factory()->create(['email' => 'donor@example.test']);
    $other = User::factory()->create(['email' => 'other@example.test']);

    $token = app('auth.password.broker')->createToken($other);

    $this->post('/reset-password', [
        'token' => $token,
        'email' => 'donor@example.test',
        'password' => 'a-brand-new-passphrase',
        'password_confirmation' => 'a-brand-new-passphrase',
    ])->assertSessionHasErrors('email');

    expect(Hash::check('password', (string) $user->fresh()->password))->toBeTrue();
});

it('tells the account holder that their password changed', function () {
    /*
     * Nobody asks for this email, and it is the only thing that turns a silent
     * account takeover into one the owner finds out about.
     */
    $user = User::factory()->create(['email' => 'donor@example.test']);

    $token = app('auth.password.broker')->createToken($user);

    $this->post('/reset-password', [
        'token' => $token,
        'email' => 'donor@example.test',
        'password' => 'a-brand-new-passphrase',
        'password_confirmation' => 'a-brand-new-passphrase',
    ]);

    expect(EmailLog::where('template_key', 'account.password_changed')->count())->toBe(1);
});

it('invalidates the remember token when the password is reset', function () {
    // If the reason for this reset is a stolen password, whoever stole it holds
    // a remember cookie that stays valid until this rotates.
    $user = User::factory()->create(['email' => 'donor@example.test']);
    $before = $user->remember_token;

    $token = app('auth.password.broker')->createToken($user);

    $this->post('/reset-password', [
        'token' => $token,
        'email' => 'donor@example.test',
        'password' => 'a-brand-new-passphrase',
        'password_confirmation' => 'a-brand-new-passphrase',
    ]);

    expect($user->fresh()->remember_token)->not->toBe($before);
});

// ── The account area ────────────────────────────────────────────────────────

it('keeps the account area shut to guests', function (string $path) {
    $this->get($path)->assertRedirect(route('login'));
})->with(['/account', '/account/profile', '/account/security']);

it('shows a verified donor their giving', function () {
    $user = User::factory()->create();
    $donor = Donor::factory()->create(['user_id' => $user->getKey()]);

    Donation::factory()->completed()->create([
        'donor_id' => $donor->getKey(),
        'reference' => 'SCGHF-D-TEST-1',
    ]);

    $this->actingAs($user)->get('/account')
        ->assertOk()
        ->assertSee('SCGHF-D-TEST-1');
});

it('shows nothing alarming to a donor who has not given yet', function () {
    // Most people who create an account here will have given nothing. An empty
    // table under "no donations found" reads as money gone missing.
    $user = User::factory()->create();

    $this->actingAs($user)->get('/account')->assertOk();
});

it('lets a donor reach their security page before verifying', function () {
    /*
     * Somebody who suspects the account was created by somebody else needs to
     * change the password, and that must not require verifying an address they
     * may not control.
     */
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->get('/account/security')->assertOk();
});

it('shows a donor their own failed sign-ins as well as the successes', function () {
    // A run of failures followed by one success is the shape of a password that
    // was eventually guessed, and it is invisible if only successes are listed.
    $user = User::factory()->create();

    LoginHistory::create([
        'user_id' => $user->getKey(),
        'email_attempted' => $user->email,
        'outcome' => LoginOutcome::Failed,
        'ip_address' => '41.66.0.1',
    ]);

    $this->actingAs($user)->get('/account/security')
        ->assertOk()
        ->assertSee(LoginOutcome::Failed->label());
});

// ── Profile and preferences ─────────────────────────────────────────────────

it('lets a donor correct their own details', function () {
    $user = User::factory()->create(['name' => 'Ama Mensa']);

    $this->actingAs($user)->patch('/account/profile', [
        'name' => 'Ama Mensah',
        'accepts_privacy_policy' => '1',
    ])->assertRedirect();

    expect($user->fresh()->name)->toBe('Ama Mensah');
});

it('will not let the profile form change what kind of account this is', function () {
    /*
     * `type` and `is_active` are both in $fillable, because the admin panel
     * legitimately sets them. The profile form must build its own attribute
     * list rather than passing `validated()` through, or a rule added
     * carelessly later silently becomes a field the public can set.
     */
    $user = User::factory()->create();

    $this->actingAs($user)->patch('/account/profile', [
        'name' => 'Ama Mensah',
        'type' => 'staff',
        'is_active' => '1',
        'suspended_at' => null,
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($user->fresh()->type)->toBe(UserType::Donor);
});

it('suppresses marketing when the box is unticked, not just the checkbox', function () {
    /*
     * `accepts_email_marketing` is what the campaign builder reads.
     * `suppressions` is what MessageDispatcher reads, on every message. Writing
     * only the first means the box shows unticked and the next appeal goes out
     * anyway.
     */
    $user = User::factory()->create([
        'email' => 'donor@example.test',
        'accepts_email_marketing' => true,
    ]);

    $this->actingAs($user)->patch('/account/profile', [
        'name' => $user->name,
        'accepts_email_marketing' => '0',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($user->fresh()->accepts_email_marketing)->toBeFalse();

    $suppression = Suppression::where('address', 'donor@example.test')->first();

    expect($suppression)->not->toBeNull()
        // Marketing scope, never all. An unsubscribe stops appeals; it must not
        // stop a receipt or a password reset.
        ->and($suppression->scope)->toBe(Suppression::SCOPE_MARKETING);
});

it('lets somebody opt back in', function () {
    $user = User::factory()->create([
        'email' => 'donor@example.test',
        'accepts_email_marketing' => false,
    ]);

    Suppression::record(
        channel: Suppression::CHANNEL_EMAIL,
        address: 'donor@example.test',
        reason: Suppression::REASON_UNSUBSCRIBE,
    );

    $this->actingAs($user)->patch('/account/profile', [
        'name' => $user->name,
        'accepts_email_marketing' => '1',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(Suppression::where('address', 'donor@example.test')->whereNull('released_at')->exists())
        ->toBeFalse();
});

it('refuses to clear a hard bounce because somebody ticked a box', function () {
    /*
     * Releasing a hard bounce sends mail to a mailbox that does not exist,
     * which is the behaviour that gets a sending domain blocklisted — and the
     * damage lands on everybody else's receipts.
     */
    $user = User::factory()->create(['email' => 'donor@example.test']);

    Suppression::record(
        channel: Suppression::CHANNEL_EMAIL,
        address: 'donor@example.test',
        reason: Suppression::REASON_HARD_BOUNCE,
    );

    $this->actingAs($user)->patch('/account/profile', [
        'name' => $user->name,
        'accepts_email_marketing' => '1',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(Suppression::where('address', 'donor@example.test')->whereNull('released_at')->exists())
        ->toBeTrue();
});

// ── Changing a password while signed in ─────────────────────────────────────

it('requires the current password to change it', function () {
    /*
     * Without this, a session left open on a shared computer — an internet
     * café, a phone lent to somebody — is enough to lock the owner out of their
     * own giving history.
     */
    $user = User::factory()->create();

    $this->actingAs($user)->put('/account/security/password', [
        'current_password' => 'not-the-password',
        'password' => 'a-brand-new-passphrase',
        'password_confirmation' => 'a-brand-new-passphrase',
    ])->assertSessionHasErrors('current_password');

    expect(Hash::check('password', (string) $user->fresh()->password))->toBeTrue();
});

it('changes the password and emails to say so', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->put('/account/security/password', [
        'current_password' => 'password',
        'password' => 'a-brand-new-passphrase',
        'password_confirmation' => 'a-brand-new-passphrase',
    ])->assertRedirect();

    expect(Hash::check('a-brand-new-passphrase', (string) $user->fresh()->password))->toBeTrue()
        ->and(EmailLog::where('template_key', 'account.password_changed')->count())->toBe(1);
});

it('keeps this session signed in after the change', function () {
    // Rotating the token signs out every OTHER device. Signing out the browser
    // that just made the change reads as "it failed" to the person who made it.
    $user = User::factory()->create();

    $this->actingAs($user)->put('/account/security/password', [
        'current_password' => 'password',
        'password' => 'a-brand-new-passphrase',
        'password_confirmation' => 'a-brand-new-passphrase',
    ]);

    expect(Auth::check())->toBeTrue();
});

// ── The pages render ────────────────────────────────────────────────────────

it('renders every public account page', function (string $path) {
    $this->get($path)->assertOk();
})->with(['/login', '/register', '/forgot-password']);

it('sends a signed-in visitor away from the sign-in form', function () {
    $this->actingAs(User::factory()->create())->get('/login')->assertRedirect('/account');
});

/** A valid, signed verification URL for a user. */
function verificationUrlFor(User $user): string
{
    return URL::temporarySignedRoute('verification.verify', now()->addHour(), [
        'ulid' => $user->ulid,
        'hash' => sha1($user->getEmailForVerification()),
    ]);
}
