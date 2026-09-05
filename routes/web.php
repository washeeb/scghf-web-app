<?php

declare(strict_types=1);

use App\Http\Controllers\Account\DashboardController;
use App\Http\Controllers\Account\EmailController;
use App\Http\Controllers\Account\ProfileController;
use App\Http\Controllers\Account\SecurityController;
use App\Http\Controllers\Account\TwoFactorController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Http\Controllers\DeliveryWebhookController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\PaystackWebhookController;
use Illuminate\Support\Facades\Route;
use Spatie\Honeypot\ProtectAgainstSpam;

/*
|--------------------------------------------------------------------------
| The public site
|--------------------------------------------------------------------------
|
| A home route so the layout shell is reachable and testable. CMS page
| rendering — the page builder, blocks, templates — arrives in Phase 5 and
| replaces this rather than being added alongside it.
*/
Route::get('/', [PageController::class, 'home'])->name('home');

/*
|--------------------------------------------------------------------------
| Payment webhooks
|--------------------------------------------------------------------------
|
| Registered from config rather than hardcoded, so the path can be changed
| without a deploy if it ever needs to be — a webhook URL is effectively
| public, and being able to rotate it is worth the indirection.
|
| CSRF is exempted in bootstrap/app.php: Paystack is a server, it has no
| session and no token, and the endpoint is authenticated by the HMAC
| signature instead. That is a stronger check than CSRF, not a weaker one.
|
| Deliberately NOT rate-limited. Paystack retries on any non-2xx, so throttling
| it into 429s would turn a busy minute into a retry storm — and the endpoint
| already answers 200 to everything, storing rather than trusting.
*/
Route::post(
    (string) config('payments.paystack.webhook_path', '/webhooks/paystack'),
    PaystackWebhookController::class,
)->name('webhooks.paystack');

/*
|--------------------------------------------------------------------------
| Delivery webhooks — bounces, complaints and delivery reports
|--------------------------------------------------------------------------
|
| One route for every provider, distinguished by a path segment, because the
| difference between them is a signature scheme rather than a workflow.
|
| Under `/webhooks/` because that is the prefix bootstrap/app.php exempts from
| CSRF. A provider posts from a server: it holds no session and no token, and
| the endpoint is authenticated by its HMAC signature instead — a stronger check
| than CSRF, which only proves a request came from our own page.
|
| Not rate-limited, for the same reason the Paystack endpoint is not: providers
| retry on any non-2xx, so throttling into 429s turns a busy minute into a retry
| storm. The endpoint answers 200 to everything and stores rather than trusts.
*/
Route::post(
    trim((string) config('communications.webhooks.path_prefix', 'webhooks/delivery'), '/').'/{provider}',
    DeliveryWebhookController::class,
)->name('webhooks.delivery');

/*
|--------------------------------------------------------------------------
| Public accounts
|--------------------------------------------------------------------------
|
| Registration, sign-in, verification and password reset for DONORS. Staff sign
| in at the Filament panel, where the mandatory second factor lives — see
| App\Http\Controllers\Auth\LoginController for why a public form that accepted
| staff would be a bypass of it.
|
| Plain controllers and full page POSTs rather than Livewire components. This is
| the one part of the site that has to work on a five-year-old Android phone on
| a 2G fallback, with whatever the browser has decided to do to the JavaScript —
| and there is nothing here that a form post does not do well.
|
| Every write path is rate limited. The numbers come from config/security.php,
| which has carried them since Phase 2 with nothing reading them.
*/
Route::middleware('guest')->group(function (): void {
    Route::get('register', [RegisterController::class, 'show'])->name('register');
    Route::post('register', [RegisterController::class, 'store'])
        // The honeypot is a hidden field plus a minimum fill time. It stops the
        // volume bots, which are most of them, without a CAPTCHA — and a CAPTCHA
        // on a donation site is a wall in front of the people least able to get
        // over it.
        ->middleware(['throttle:register', ProtectAgainstSpam::class]);

    Route::get('login', [LoginController::class, 'show'])->name('login');
    Route::post('login', [LoginController::class, 'store']);

    Route::get('forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('forgot-password', [PasswordResetController::class, 'email'])
        ->middleware('throttle:password-reset')
        ->name('password.email');

    Route::get('reset-password/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
    Route::post('reset-password', [PasswordResetController::class, 'update'])
        ->middleware('throttle:password-reset')
        ->name('password.store');
});

Route::post('logout', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');

/*
| Email verification.
|
| The link itself is NOT behind `auth`: somebody who registers on a phone and
| opens the link on a laptop is not signed in there, and bouncing them to a
| login form at that moment loses them. `signed` is the authentication — the
| signature cannot be produced without the application key, and it expires.
*/
Route::get('verify-email', [EmailVerificationController::class, 'notice'])
    ->middleware('auth')
    ->name('verification.notice');

Route::get('verify-email/{ulid}/{hash}', [EmailVerificationController::class, 'verify'])
    ->middleware('signed')
    ->name('verification.verify');

Route::post('verify-email/resend', [EmailVerificationController::class, 'resend'])
    ->middleware(['auth', 'throttle:verification'])
    ->name('verification.send');

/*
| The account area.
|
| `auth.session` is Illuminate\Session\Middleware\AuthenticateSession, and it is
| what makes "signed out everywhere else" true rather than a sentence in a flash
| message: without it, changing the password invalidates nothing for a session
| that is already open on another device.
|
| `verified` guards the dashboard and not the whole group, deliberately. An
| unverified account must still be able to reach its own security page to change
| a password — that is the first thing somebody does when they suspect the
| account was created by somebody else.
*/
Route::middleware(['auth', 'auth.session'])
    ->prefix('account')
    ->name('account.')
    ->group(function (): void {
        Route::get('/', DashboardController::class)->middleware('verified')->name('dashboard');

        Route::get('profile', [ProfileController::class, 'edit'])->name('profile');
        Route::patch('profile', [ProfileController::class, 'update'])->name('profile.update');

        Route::get('security', [SecurityController::class, 'show'])->name('security');
        Route::put('security/password', [SecurityController::class, 'updatePassword'])->name('password.update');

        /*
         * Changing the address. Rate limited on the same bucket as password
         * resets, because it is the same kind of thing: an email this server
         * sends on demand to an address somebody typed.
         */
        Route::post('email', [EmailController::class, 'request'])
            ->middleware('throttle:password-reset')
            ->name('email.request');

        // Two-factor. Every write here also asks for the current password —
        // adding a factor to somebody else's account locks them out of it just
        // as effectively as removing one lets an attacker in.
        Route::get('security/two-factor', [TwoFactorController::class, 'create'])->name('two-factor.create');
        Route::post('security/two-factor', [TwoFactorController::class, 'store'])->name('two-factor.store');
        Route::delete('security/two-factor', [TwoFactorController::class, 'destroy'])->name('two-factor.destroy');
        Route::post('security/two-factor/recovery-codes', [TwoFactorController::class, 'regenerate'])
            ->name('two-factor.recovery');
    });

/*
|--------------------------------------------------------------------------
| The second step, for a donor who has turned it on
|--------------------------------------------------------------------------
|
| `guest`, because nobody is signed in while this page is open. The password
| has been checked and the account has deliberately NOT been authenticated —
| all that exists is an id in the session saying who is halfway through. A
| factor somebody can skip by closing the tab is not a factor.
*/
Route::middleware('guest')->group(function (): void {
    Route::get('two-factor-challenge', [TwoFactorChallengeController::class, 'show'])
        ->name('two-factor.challenge');

    Route::post('two-factor-challenge', [TwoFactorChallengeController::class, 'store']);
});

/*
| Confirming or cancelling a change of email address.
|
| Neither is behind `auth`, and for different reasons. The CONFIRM link is
| opened from the new inbox, possibly on a different device from the one that
| asked. The CANCEL link is opened by somebody who may be locked out of their
| own session — which is the entire situation it exists for.
|
| Both are `signed`, and both check a hash of the pending address, so a link
| issued for one requested change cannot confirm a different one.
*/
Route::get('account/email/confirm/{ulid}/{hash}', [EmailController::class, 'confirm'])
    ->middleware('signed')
    ->name('account.email.confirm');

Route::get('account/email/cancel/{ulid}/{hash}', [EmailController::class, 'cancel'])
    ->middleware('signed')
    ->name('account.email.cancel');

/*
|--------------------------------------------------------------------------
| CMS pages
|--------------------------------------------------------------------------
|
| ⚠ LAST IN THE FILE, AND IT HAS TO BE.
|
| `{path}` with `.*` matches everything, including `/login`, `/account` and the
| webhook endpoints. Laravel matches routes in the order they are registered, so
| every named route above wins — and a route added BELOW this one would never be
| reached at all, silently, with the CMS answering 404 for it.
|
| Anything new goes above this block.
*/
/*
 * `{page:ulid}`, not `{page}`.
 *
 * `Page::getRouteKeyName()` is `path`, so the default binding would look this
 * up by `/about/leadership` — which contains slashes and cannot be one route
 * segment. The ULID is what §1.1 already requires of anything in a URL, and it
 * does not move when a page is re-slugged or re-parented.
 */
Route::get('pages/{page:ulid}/preview', [PageController::class, 'preview'])
    /*
     * Signed AND authenticated. A signed URL is still a string somebody can
     * paste into a chat, so the signature only proves the link came from the
     * panel; the policy check in the controller proves the person opening it is
     * entitled to see an unpublished page.
     */
    ->middleware(['signed', 'auth'])
    ->name('pages.preview');

Route::get('/{path}', [PageController::class, 'show'])
    ->where('path', '.*')
    ->name('pages.show');
