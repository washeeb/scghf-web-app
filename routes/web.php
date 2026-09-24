<?php

declare(strict_types=1);

use App\Http\Controllers\Account\DashboardController;
use App\Http\Controllers\Account\EmailController;
use App\Http\Controllers\Account\ImpactController as AccountImpactController;
use App\Http\Controllers\Account\PrivacyController;
use App\Http\Controllers\Account\ProfileController;
use App\Http\Controllers\Account\ReceiptsController;
use App\Http\Controllers\Account\RegularGivingController;
use App\Http\Controllers\Account\SecurityController;
use App\Http\Controllers\Account\TwoFactorController;
use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Http\Controllers\BeneficiaryDocumentController;
use App\Http\Controllers\CauseController;
use App\Http\Controllers\Chat\ChatController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\Courier\CourierController;
use App\Http\Controllers\CspReportController;
use App\Http\Controllers\CurrencyController;
use App\Http\Controllers\DeliveryWebhookController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\DonateController;
use App\Http\Controllers\EnquiryController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\FakeCheckoutController;
use App\Http\Controllers\FaqController;
use App\Http\Controllers\FocusAreaController;
use App\Http\Controllers\GalleryController;
use App\Http\Controllers\GivingController;
use App\Http\Controllers\GrantDocumentController;
use App\Http\Controllers\ImpactController;
use App\Http\Controllers\NewsController;
use App\Http\Controllers\NewsletterController;
use App\Http\Controllers\OpcacheResetController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\PartnersController;
use App\Http\Controllers\PaystackWebhookController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\PwaController;
use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\ScreenController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\Shop\CartController;
use App\Http\Controllers\Shop\CheckoutController;
use App\Http\Controllers\Shop\DownloadController;
use App\Http\Controllers\Shop\InvoiceController;
use App\Http\Controllers\Shop\ShopController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\TestimonialsController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\TrackingController;
use App\Http\Controllers\VolunteerController;
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

// Meta verifies a webhook subscription with a GET before it sends anything.
Route::get(
    trim((string) config('communications.webhooks.path_prefix', 'webhooks/delivery'), '/').'/{provider}',
    [DeliveryWebhookController::class, 'subscribe'],
)->name('webhooks.delivery.verify');

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

        // The story and the paperwork: gifts and what followed them; receipts by tax year.
        Route::get('impact', AccountImpactController::class)->middleware('verified')->name('impact');
        Route::get('receipts', ReceiptsController::class)->middleware('verified')->name('receipts');

        Route::get('profile', [ProfileController::class, 'edit'])->name('profile');

        // Standing gifts: see, pause, resume, change the amount, stop.
        Route::get('giving', [RegularGivingController::class, 'index'])->name('giving');
        Route::patch('profile', [ProfileController::class, 'update'])->name('profile.update');

        Route::get('security', [SecurityController::class, 'show'])->name('security');
        Route::put('security/password', [SecurityController::class, 'updatePassword'])->name('password.update');
        Route::post('security/sessions/revoke', [SecurityController::class, 'logoutEverywhere'])->name('sessions.revoke');

        // Act 843: a copy of your data, and the end of your account.
        Route::get('privacy', [PrivacyController::class, 'show'])->name('privacy');
        Route::post('privacy/export', [PrivacyController::class, 'export'])->middleware('throttle:5,60')->name('privacy.export');
        Route::delete('privacy', [PrivacyController::class, 'destroy'])->middleware('throttle:3,60')->name('privacy.destroy');

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
| Managing a regular gift
|--------------------------------------------------------------------------
|
| Signed in, or from the signed link in every recurring-giving email — a donor
| who set up a monthly gift at a church event has no account and should not
| need one to stop it. `GivingController::authorise()` accepts either; nothing
| here is behind `auth`, and nothing changes on a GET.
*/
Route::get('giving/{subscription:ulid}', [RegularGivingController::class, 'manage'])->name('giving.manage');
Route::post('giving/{subscription:ulid}/pause', [RegularGivingController::class, 'pause'])->name('giving.pause');
Route::post('giving/{subscription:ulid}/resume', [RegularGivingController::class, 'resume'])->name('giving.resume');
Route::post('giving/{subscription:ulid}/amount', [RegularGivingController::class, 'amount'])->name('giving.amount');
Route::post('giving/{subscription:ulid}/cancel', [RegularGivingController::class, 'cancel'])->name('giving.cancel');

/*
|--------------------------------------------------------------------------
| The announcement bar
|--------------------------------------------------------------------------
|
| A click goes through the application so it can be counted — `clicks` has been
| on the table since Phase 3 and incremented by nothing, which left the
| foundation unable to answer whether a bar across every page is worth the strip
| of a phone screen it costs.
|
| Dismissal is a POST, not a GET. It changes state (a cookie that lasts a
| month), and a GET that changes state is one a link prefetcher can fire on
| somebody's behalf — which would close the notice for a visitor who never
| touched it.
*/
Route::get('announcements/{announcement:ulid}/go', [AnnouncementController::class, 'click'])
    ->name('announcements.click');

Route::post('announcements/{announcement:ulid}/dismiss', [AnnouncementController::class, 'dismiss'])
    ->name('announcements.dismiss');

/*
|--------------------------------------------------------------------------
| The public content pages
|--------------------------------------------------------------------------
|
| Everything here reads content the CMS already manages. None of it is a new
| kind of data — the news posts, FAQs, galleries, documents, team and partners
| all had admin screens in Phase 5 and nowhere to be seen.
|
| ⚠ All of it must stay ABOVE the CMS catch-all at the bottom of this file.
| `{path}` with `.*` matches everything, and a route registered below it is a
| route that is never reached — silently, with the CMS answering 404 for it.
*/
Route::get('news', [NewsController::class, 'index'])->name('news.index');
Route::get('news/{post:slug}', [NewsController::class, 'show'])->name('news.show');
Route::get('news/category/{category:slug}', [NewsController::class, 'category'])->name('news.category');

Route::get('faq', FaqController::class)->name('faq');

Route::get('galleries', [GalleryController::class, 'index'])->name('galleries.index');
Route::get('galleries/{gallery:slug}', [GalleryController::class, 'show'])->name('galleries.show');

/*
 * Reports, policies and financial statements.
 *
 * A Ghanaian non-profit asking the public for money is expected to publish its
 * accounts and its safeguarding policy, and a donor deciding whether to trust a
 * payment form looks for exactly those. The download route counts each fetch
 * and refuses anything marked as needing a sign-in.
 */
Route::get('reports', [DocumentController::class, 'index'])->name('documents.index');
Route::get('reports/{document:slug}/download', [DocumentController::class, 'download'])
    ->name('documents.download');

Route::get('team', TeamController::class)->name('team');
Route::get('partners', PartnersController::class)->name('partners');
Route::get('testimonials', TestimonialsController::class)->name('testimonials');

Route::get('search', SearchController::class)->middleware('feature:site_search')->name('search');

/*
| Contact.
|
| The form is the missing half of a feature that has been complete on the admin
| side since Phase 5: `contact_messages`, the departments, the inbox, the
| routing of confidential enquiries and the acknowledgement template all
| existed, and nothing could create a message.
*/
Route::get('contact', [ContactController::class, 'show'])->name('contact');
Route::post('contact', [ContactController::class, 'store'])
    ->middleware(ProtectAgainstSpam::class)
    ->name('contact.store');

/*
| Newsletter, double opt-in.
|
| `Subscriber::recordConsent()`, `confirm()` and `unsubscribe()` were written in
| Phase 3 and the confirmation email was seeded with them. Nothing called any of
| it, and the footer's signup form checked `Route::has('newsletter.subscribe')`
| and rendered nothing — so the form has been invisible on every page since
| Phase 4.
|
| The confirm and unsubscribe links are opened from an inbox, so neither can be
| behind a session. Both are keyed on a token rather than an address, because a
| URL containing somebody's email address is a URL that leaks it into every
| referrer header and browser history it touches.
*/
Route::post('newsletter/subscribe', [NewsletterController::class, 'subscribe'])
    ->middleware(ProtectAgainstSpam::class)
    ->name('newsletter.subscribe');

Route::get('newsletter/confirm/{token}', [NewsletterController::class, 'confirm'])
    ->name('newsletter.confirm');

Route::get('newsletter/unsubscribe/{token}', [NewsletterController::class, 'unsubscribe'])
    ->name('newsletter.unsubscribe');

// The preference centre: the same token as the unsubscribe link, so it can
// be reached from any email without an account. GET shows, POST changes.
Route::get('newsletter/preferences/{token}', [NewsletterController::class, 'preferences'])
    ->name('newsletter.preferences');
Route::post('newsletter/preferences/{token}', [NewsletterController::class, 'updatePreferences'])
    ->middleware('throttle:10,1')
    ->name('newsletter.preferences.update');

/*
|--------------------------------------------------------------------------
| The courier portal
|--------------------------------------------------------------------------
|
| A rider or courier agent signs in at the ordinary /login and comes here:
| the deliveries in their hands, and what they can do to each from the door.
| `can:deliveries.courier` is the Courier role; the office's side is in the
| admin (Shop → Deliveries, and the order page's "Assign a courier").
*/
Route::middleware(['auth', 'auth.session', 'can:deliveries.courier'])
    ->prefix('courier')
    ->name('courier.')
    ->group(function (): void {
        Route::get('/', [CourierController::class, 'index'])->name('index');
        Route::get('{delivery}', [CourierController::class, 'show'])->name('show');
        Route::post('{delivery}/picked-up', [CourierController::class, 'pickedUp'])->name('picked-up');
        Route::post('{delivery}/out-for-delivery', [CourierController::class, 'outForDelivery'])->name('out-for-delivery');
        Route::post('{delivery}/delivered', [CourierController::class, 'delivered'])->name('delivered');
        Route::post('{delivery}/failed', [CourierController::class, 'failed'])->name('failed');
    });

// The proof photograph: the courier who took it, or staff who may see deliveries.
Route::get('deliveries/{delivery}/proof', [CourierController::class, 'proof'])
    ->middleware(['auth', 'auth.session'])
    ->name('deliveries.proof');

/*
|--------------------------------------------------------------------------
| Live chat — the visitor's end
|--------------------------------------------------------------------------
|
| Four JSON endpoints behind the widget (resources/js/chat.js). Starting a
| chat is limited per IP because each one emails the office; the rest are
| limited to what a person typing can produce. The conversation's own token
| (header X-Chat-Token) is the credential; a wrong one is a 404.
*/
Route::prefix('chat')->name('chat.')->group(function (): void {
    Route::get('status', [ChatController::class, 'status'])->middleware('throttle:60,1')->name('status');
    Route::post('start', [ChatController::class, 'start'])->middleware('throttle:5,10')->name('start');
    Route::get('{conversation}/messages', [ChatController::class, 'messages'])->middleware('throttle:120,1')->name('messages');
    Route::post('{conversation}/messages', [ChatController::class, 'send'])->middleware('throttle:30,1')->name('send');
    Route::post('{conversation}/close', [ChatController::class, 'close'])->middleware('throttle:10,1')->name('close');
});

/*
| Open and click tracking, only when switched on in .env and only on
| marketing mail. The pixel answers whatever happens; the click redirect
| accepts only a signed, absolute destination.
*/
/*
 * CSP violation reports. Browsers POST here with no CSRF token and a JSON
 * body of their own content type; the exemption is in bootstrap/app.php.
 */
Route::post('csp-report', CspReportController::class)
    ->middleware('throttle:30,1')
    ->name('csp.report');

Route::get('t/o/{log}', [TrackingController::class, 'open'])->name('track.open');
Route::get('t/c/{log}', [TrackingController::class, 'click'])->name('track.click');

/*
|--------------------------------------------------------------------------
| Giving by card or mobile money
|--------------------------------------------------------------------------
|
| ⚠ `/donate/callback` is what `PAYSTACK_CALLBACK_URL` has pointed at in
| `.env.example` since Phase 2, and the route did not exist — so a real payment
| would have returned the donor to a 404 immediately after taking their money.
|
| Neither the callback nor the thank-you page trusts the query string. A donor
| coming back from Paystack proves only that a browser followed a link; the
| money is confirmed by the signed webhook, and these pages report what the
| DATABASE says.
*/
Route::get('donate', [DonateController::class, 'show'])->name('donate');

Route::post('donate', [DonateController::class, 'store'])
    /*
     * Throttled, and the reason is not abuse of the foundation. A donation
     * form is a card-testing target: somebody with a list of stolen card
     * numbers uses a real charity's checkout to find which ones still work,
     * because small gifts to a charity are the least likely charge to be
     * queried. The honeypot stops the naive version; the throttle bounds the
     * rest.
     */
    ->middleware(['throttle:6,1', ProtectAgainstSpam::class])
    ->name('donate.store');

Route::get('donate/callback', [DonateController::class, 'callback'])->name('donate.callback');

/*
| The popup checkout. A GET page, so a refresh cannot start a second
| payment: the transaction was initialised by the POST that led here and
| the page only resumes it. Once the gift is settled, the page sends the
| donor on to the thank-you.
*/
Route::get('donate/{donation:ulid}/pay', [DonateController::class, 'pay'])
    ->name('donate.pay');

Route::get('donate/{donation:ulid}/thank-you', [DonateController::class, 'thanks'])
    ->name('donate.thanks');

/*
 * The waiting page's questions.
 *
 * `status` verifies a pending gift with the gateway each time it is asked,
 * so a mobile-money approval shows within seconds rather than when the queue
 * next drains; throttled because a verify is a call to Paystack. `otp` is the
 * second step of a direct charge — the code the network texted the donor.
 */
Route::get('donate/{donation:ulid}/status', [DonateController::class, 'status'])
    ->middleware('throttle:30,1')
    ->name('donate.status');

Route::post('donate/{donation:ulid}/otp', [DonateController::class, 'otp'])
    ->middleware('throttle:6,1')
    ->name('donate.otp');

/*
 * The receipt as a PDF. Signed — the link lives in an email and on the
 * thank-you page, and the receipt names a person and an amount — or fetched by
 * the signed-in donor it belongs to.
 */
Route::get('receipts/{receipt:ulid}/download', [ReceiptController::class, 'download'])
    ->name('receipts.download');

// The invoice, by the same rule: the signed link from the confirmation, or
// the account that placed the order.
Route::get('invoices/{invoice:ulid}/download', [InvoiceController::class, 'download'])
    ->name('invoices.download');

/*
| The sandbox checkout.
|
| ⚠ `FakeGateway` has returned `/payments/fake/{reference}` as its authorization
| URL since Phase 3, and the route did not exist. `fake` is the DEFAULT driver,
| so on every developer machine, in CI, and on any staging deployment without
| live keys, starting a donation sent the donor to a 404 — the engine was fully
| tested and the JOURNEY could not be walked once, by anybody.
|
| Not registered in production, and the controller aborts there as well. A page
| that can mark a payment successful without money changing hands must not be
| one stale route cache away from existing on the live site.
*/
if (! app()->isProduction()) {
    Route::get('payments/fake/{reference}', [FakeCheckoutController::class, 'show'])
        ->name('payments.fake');

    Route::post('payments/fake/{reference}', [FakeCheckoutController::class, 'pay'])
        ->name('payments.fake.pay');
}

/*
|--------------------------------------------------------------------------
| The programmatic pages
|--------------------------------------------------------------------------
|
| What the foundation does (focus areas), what it is doing (projects) and what
| people can give to (causes).
|
| `/what-we-do` rather than `/focus-areas`: the visitor's phrasing, not the
| database's. The slug is what an editor sees in the admin; the URL is what a
| donor reads in a link.
*/
Route::get('what-we-do', [FocusAreaController::class, 'index'])->name('focus-areas.index');
Route::get('what-we-do/{focusArea:slug}', [FocusAreaController::class, 'show'])->name('focus-areas.show');

Route::get('projects', [ProjectController::class, 'index'])->name('projects.index');
Route::get('projects/{project:slug}', [ProjectController::class, 'show'])->name('projects.show');

Route::get('appeals', [CauseController::class, 'index'])->name('causes.index');

/*
 * The transparency page.
 *
 * Raised AND paid out, side by side. Publishing "raised" alone is the number
 * every charity publishes and it answers nothing a sceptical donor is asking;
 * what went out is the claim that can be checked.
 */
Route::get('impact', ImpactController::class)->name('impact');
Route::get('appeals/{cause:slug}', [CauseController::class, 'show'])->name('causes.show');

/*
 * How to give without a card.
 *
 * The `banking.*` settings — bank, branch, account name and number, SWIFT, and
 * the Mobile Money merchant details — have been seeded since Phase 3 and read
 * by NOTHING. For a Ghanaian foundation that is not a minor omission: mobile
 * money is how a large share of giving actually happens, and a supporter who
 * cannot find the merchant number gives nothing rather than reaching for a card.
 */
Route::get('give', GivingController::class)->name('give');

/*
|--------------------------------------------------------------------------
| The shop
|--------------------------------------------------------------------------
|
| The catalogue, the stock ledger, carts, coupons, shipping zones, orders and
| invoices have all existed since Phase 3 — built and tested, with no page that
| showed a product, no way to put one in a basket, and `FEATURE_SHOP=true` in
| front of none of it. These routes are what the flag has been promising.
|
| `feature:shop` is the switch. Turning the flag off makes every one of these a
| 404, which is what a paused shop should look like — indistinguishable from
| one that was never built.
|
| Every write is a plain form POST. The basket works on a phone with the
| JavaScript turned off, because that is the phone most of these customers
| have.
*/
Route::middleware('feature:shop')->group(function (): void {
    Route::get('shop', [ShopController::class, 'index'])->name('shop.index');
    Route::get('shop/category/{category:slug}', [ShopController::class, 'category'])->name('shop.category');

    Route::get('basket', [CartController::class, 'show'])->name('shop.cart');
    Route::post('basket', [CartController::class, 'add'])->middleware('throttle:30,1')->name('shop.cart.add');
    Route::patch('basket/{variant:ulid}', [CartController::class, 'update'])->name('shop.cart.update');
    Route::delete('basket/{variant:ulid}', [CartController::class, 'remove'])->name('shop.cart.remove');

    // Throttled harder than the rest: a coupon field is a guessing target, and
    // ten tries a minute is plenty for somebody typing a code off a flyer.
    Route::post('basket/coupon', [CartController::class, 'applyCoupon'])->middleware('throttle:10,1')->name('shop.cart.coupon');
    Route::delete('basket/coupon', [CartController::class, 'removeCoupon'])->name('shop.cart.coupon.remove');

    Route::get('checkout', [CheckoutController::class, 'show'])->name('shop.checkout');
    Route::post('checkout', [CheckoutController::class, 'store'])
        // The same reasoning as the donation form: a checkout is a
        // card-testing target, and the honeypot stops the naive version.
        ->middleware(['throttle:6,1', ProtectAgainstSpam::class])
        ->name('shop.checkout.store');

    Route::get('checkout/callback', [CheckoutController::class, 'callback'])->name('shop.checkout.callback');

    // A guest finding their order: reference plus the email or phone it was
    // placed with. Throttled, because it is a lookup against personal data.
    Route::get('shop/orders/track', [CheckoutController::class, 'track'])->name('shop.track');
    Route::post('shop/orders/track', [CheckoutController::class, 'lookup'])
        ->middleware('throttle:10,1')
        ->name('shop.track.lookup');

    Route::get('shop/orders/{order:ulid}', [CheckoutController::class, 'order'])->name('shop.order');

    // A paid file. The token is the whole credential; the file is on a disk
    // nothing else serves.
    Route::get('downloads/{token:token}', [DownloadController::class, 'show'])
        ->middleware('throttle:30,1')
        ->name('shop.download');

    // Last, because `{product}` would otherwise swallow `category`.
    Route::get('shop/{product:slug}', [ShopController::class, 'show'])->name('shop.show');
});

/*
|--------------------------------------------------------------------------
| Events
|--------------------------------------------------------------------------
|
| Events and their registrations have existed since Phase 3 with no admin
| screen and no page. Registration is a plain form; over capacity is a waiting
| list; the confirmation email that was seeded in Phase 3 finally has a caller.
| Paid tickets stay behind FEATURE_EVENT_TICKETING, off.
*/
Route::middleware('feature:events')->group(function (): void {
    /*
     * A ticket on a phone. Signed, no expiry — the email link must work
     * on the day however old the order is — and one code names one ticket.
     */
    Route::get('tickets/{ticket:code}', [TicketController::class, 'show'])->middleware('signed')->name('tickets.show');
    Route::get('tickets/{ticket:code}/qr.svg', [TicketController::class, 'qr'])->middleware('signed')->name('tickets.qr');

    Route::get('events', [EventController::class, 'index'])->name('events.index');
    Route::get('events/{event:slug}', [EventController::class, 'show'])->name('events.show');
    Route::post('events/{event:slug}/register', [EventController::class, 'register'])
        ->middleware(['throttle:10,1', ProtectAgainstSpam::class])
        ->name('events.register');
    Route::get('events/registrations/{registration:ulid}', [EventController::class, 'registered'])
        ->name('events.registered');
});

/*
|--------------------------------------------------------------------------
| Getting involved
|--------------------------------------------------------------------------
|
| Volunteering: the open roles and the application form, whose table, checks
| and workflow have existed since Phase 3 with no way in. And the structured
| enquiries — partner, corporate, in-kind, fundraise — which are a CMS block on
| whichever page the editor places them, posting here to land in the contact
| inbox under the right department.
*/
Route::middleware('feature:volunteers')->group(function (): void {
    Route::get('volunteer', [VolunteerController::class, 'index'])->name('volunteer.index');
    Route::get('volunteer/apply', [VolunteerController::class, 'general'])->name('volunteer.general');
    Route::post('volunteer/apply', [VolunteerController::class, 'apply'])
        ->middleware(['throttle:5,1', ProtectAgainstSpam::class])
        ->name('volunteer.apply.general');
    Route::get('volunteer/applications/{application:ulid}', [VolunteerController::class, 'applied'])
        ->name('volunteer.applied');
    Route::get('volunteer/{opportunity:slug}', [VolunteerController::class, 'show'])->name('volunteer.show');
    Route::post('volunteer/{opportunity:slug}/apply', [VolunteerController::class, 'apply'])
        ->middleware(['throttle:5,1', ProtectAgainstSpam::class])
        ->name('volunteer.apply');
});

Route::post('enquiries/{kind}', [EnquiryController::class, 'store'])
    ->where('kind', '[a-z-]+')
    ->middleware(['throttle:5,1', ProtectAgainstSpam::class])
    ->name('enquiries.store');

/*
|--------------------------------------------------------------------------
| Crawlers
|--------------------------------------------------------------------------
|
| `sitemap.xml` is a query, not a crawl — see SitemapController. `robots.txt` is
| a route rather than the static file that used to sit in public/, because the
| static one allowed every crawler in regardless of the indexing setting, on
| staging included.
*/
Route::get('sitemap.xml', [SitemapController::class, 'sitemap'])->name('sitemap');
Route::get('sitemaps/{type}.xml', [SitemapController::class, 'type'])->where('type', '[a-z]+')->name('sitemap.type');
Route::get('robots.txt', [SitemapController::class, 'robots'])->name('robots');

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

/*
 * The admin manual's screenshots (resources/manual/images), for the Help
 * page in the panel. Signed-in staff only: the pictures show the admin.
 */
/*
 * A case document (Wave 2). Signed for five minutes by the case page, and
 * the policy decides per document; the audit trail records every open.
 */
Route::get('beneficiaries/documents/{document:ulid}/download', [BeneficiaryDocumentController::class, 'download'])
    ->middleware('auth')
    ->name('beneficiaries.documents.download');

// A grant's file (Wave 2): signed for a day, staff who may see grants.
Route::get('grants/documents/{document:ulid}/download', [GrantDocumentController::class, 'download'])
    ->middleware('auth')
    ->name('grants.documents.download');

Route::get('manual/images/{file}', function (string $file) {
    abort_unless(auth()->user()?->canAccessPanel() ?? false, 403);

    $path = resource_path('manual/images/'.$file);

    abort_unless(preg_match('/^[a-z0-9._-]+\.png$/i', $file) === 1 && is_file($path), 404);

    return response()->file($path, ['Cache-Control' => 'private, max-age=86400']);
})->middleware('auth')->where('file', '[A-Za-z0-9._-]+')->name('manual.image');

/*
 * The progressive web app: manifest, icons, worker and the offline page.
 * The worker lives at the root so its scope is the whole site. Everything
 * but the worker 404s while FEATURE_PWA_OFFLINE is off; the worker is
 * always served so an installed one can fetch the version that unregisters.
 */
Route::get('manifest.webmanifest', [PwaController::class, 'manifest'])->name('pwa.manifest');
Route::get('sw.js', [PwaController::class, 'serviceWorker'])->name('pwa.sw');
Route::get('pwa/icon-{size}.png', [PwaController::class, 'icon'])->whereNumber('size')->name('pwa.icon');
Route::get('offline', [PwaController::class, 'offline'])->name('pwa.offline');

/*
 * The live thermometer for a projector, and the feed it polls. Public
 * (the link is projected and typed into phones), noindex, excluded from
 * the page cache; the feed caches itself for a few seconds.
 */
// The visitor's second currency for the approximate figures (Wave 2). A
// cookie and a redirect back; nothing is charged in it.
Route::post('currency', [CurrencyController::class, 'set'])->middleware('throttle:30,1')->name('currency.set');

Route::get('screen/{cause:slug}', [ScreenController::class, 'show'])->name('screen.show');
Route::get('screen/{cause:slug}/feed.json', [ScreenController::class, 'feed'])->name('screen.feed');

Route::get('/{path}', [PageController::class, 'show'])
    ->where('path', '.*')
    ->name('pages.show');

/*
|--------------------------------------------------------------------------
| Deploy: OPcache reset
|--------------------------------------------------------------------------
| Called by activate.sh (through scghf:opcache-reset) after the release
| symlink moves. One-time token, rate-limited, nothing but a cache flush.
*/
Route::post('deploy/opcache-reset', OpcacheResetController::class)
    ->middleware('throttle:6,1')
    ->name('deploy.opcache-reset');
