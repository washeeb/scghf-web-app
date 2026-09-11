<?php

declare(strict_types=1);

use App\Enums\CauseStatus;
use App\Enums\DonationStatus;
use App\Models\Cause;
use App\Models\Donation;
use App\Models\PaymentTransaction;
use App\Models\Subscription;
use App\Payments\FakeGateway;
use App\Support\Settings;
use App\Support\ThemeTokens;
use Database\Seeders\CauseSeeder;
use Database\Seeders\DivisionSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\ThemeSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The donation page
|--------------------------------------------------------------------------
|
| ⚠ THE BROWSER REDIRECT IS NOT PROOF OF PAYMENT. A donor coming back from
| Paystack proves only that a browser followed a link. The money is confirmed by
| the signed webhook, and the return page reports what the DATABASE says. A page
| that trusted `?status=success` could be made to show a completed gift by
| anybody who typed the URL, and the foundation would thank somebody who had
| paid nothing.
|
| ⚠ THE SANDBOX CHECKOUT HAD NO ROUTE. `FakeGateway` — the DEFAULT driver — has
| returned `/payments/fake/{reference}` as its authorization URL since Phase 3,
| and that route did not exist. On every developer machine, in CI and on any
| staging deployment, starting a donation sent the donor to a 404: the engine
| was fully tested and the JOURNEY could not be walked once, by anybody.
|
| ⚠ SO DID THE CALLBACK. `PAYSTACK_CALLBACK_URL` has pointed at
| `/donate/callback` in `.env.example` since Phase 2. A real payment would have
| returned the donor to a 404 immediately after taking their money.
|
| A GIFT TYPED IN CEDIS IS STORED IN PESEWAS. `Money::ofMajor()` is the only
| conversion allowed; a stray cast anywhere between the form and the gateway
| turns a fifty-cedi gift into fifty pesewas.
|
*/

beforeEach(function () {
    $this->seed(SettingsSeeder::class);
    $this->seed(ThemeSettingsSeeder::class);
    $this->seed(DivisionSeeder::class);
    $this->seed(CauseSeeder::class);

    ThemeTokens::flush();
    app(Settings::class)->flush();
});

function donationPayload(array $overrides = []): array
{
    return array_merge([
        'amount' => '50.00',
        'donor_name' => 'Ama Mensah',
        'donor_email' => 'ama@example.test',
        'consent' => '1',
    ], $overrides);
}

// ── The form ────────────────────────────────────────────────────────────────

it('opens the donation page', function () {
    $this->get(route('donate'))->assertOk()->assertSee('How much would you like to give?');
});

it('offers the preset amounts from the settings layer', function () {
    // Seeded as pesewas: 5000 is GH₵ 50.00. A preset row that printed "5000"
    // would be asking for fifty thousand cedis.
    $this->get(route('donate'))->assertOk()->assertSee('GH₵ 50.00');
});

it('pre-selects an appeal when the link names one', function () {
    $cause = Cause::create([
        'title' => 'Harvest appeal',
        'slug' => 'harvest-appeal',
        'status' => CauseStatus::Active,
        'is_published' => true,
        'published_at' => now()->subDay(),
    ]);

    $this->get(route('donate', ['cause' => $cause->slug]))
        ->assertOk()
        ->assertSee('Give to Harvest appeal');
});

// ── Making a gift ───────────────────────────────────────────────────────────

it('records a gift in pesewas and sends the donor to the gateway', function () {
    /*
     * ⚠ The conversion that must not drift. The donor typed 50; the record must
     * hold 5000. A stray `(int) $amount` here is a fifty-cedi gift becoming
     * fifty pesewas, with the right number on the form and the wrong one on
     * their bank statement.
     */
    $response = $this->post(route('donate.store'), donationPayload());

    $donation = Donation::first();

    expect($donation)->not->toBeNull()
        ->and($donation->amount_minor)->toBe(5000)
        ->and($donation->status)->toBe(DonationStatus::Pending);

    $response->assertRedirect($donation->transaction->authorization_url);
});

it('sends a gift with no appeal chosen to the General Fund', function () {
    /*
     * `donations.cause_id` is NOT NULL. Without the seeded General Fund a
     * generic gift either fails at the last step — after the donor has paid —
     * or attaches itself to whichever appeal happens to be first.
     */
    $this->post(route('donate.store'), donationPayload());

    expect(Donation::first()->cause->is_general_fund)->toBeTrue();
});

it('grosses up rather than adding the fee', function () {
    /*
     * ⚠ The fee is charged on the LARGER amount, so `amount + fee(amount)`
     * always leaves the foundation a little short of what the donor intended to
     * give. `FeeCalculator::grossUp()` is what makes "cover the fee" true.
     */
    $this->post(route('donate.store'), donationPayload(['cover_fee' => '1']));

    $donation = Donation::first();

    expect($donation->fee_covered_by_donor)->toBeTrue()
        ->and($donation->amount_minor)->toBeGreaterThan(5000);
});

it('refuses a gift smaller than it costs to process', function () {
    // The floor is a commercial fact, not a limit on generosity — and it comes
    // from the settings layer, because it moves when the gateway's pricing does.
    $this->post(route('donate.store'), donationPayload(['amount' => '0.50']))
        ->assertSessionHasErrors('amount');

    expect(Donation::count())->toBe(0);
});

it('will not take a gift without permission to hold the details', function () {
    $this->post(route('donate.store'), donationPayload(['consent' => null]))
        ->assertSessionHasErrors('consent');

    expect(Donation::count())->toBe(0);
});

it('does not require a mailing-list opt-in to accept money', function () {
    // A donation form that refuses money unless somebody joins a mailing list
    // is a donation form that loses money.
    $this->post(route('donate.store'), donationPayload())->assertRedirect();

    expect(Donation::first()->consent_email)->toBeFalse();
});

it('refuses a gift to an appeal that has closed', function () {
    /*
     * Otherwise anybody can take a payment against an appeal the foundation
     * announced it closed — and the foundation is holding money it said it had
     * stopped raising.
     */
    $cause = Cause::create([
        'title' => 'Closed appeal',
        'slug' => 'closed-appeal',
        'status' => CauseStatus::Active,
        'is_published' => true,
        'published_at' => now()->subMonth(),
        'ends_on' => now()->subWeek(),
    ]);

    $this->post(route('donate.store'), donationPayload(['cause' => $cause->slug]))
        ->assertSessionHasErrors('cause');
});

it('records a tribute gift with its dedication', function () {
    // The emotional centre of a memorial foundation.
    $this->post(route('donate.store'), donationPayload([
        'tribute_type' => 'memory',
        'tribute_name' => 'Mrs Cecilia Anyatuik Adam',
    ]));

    expect(Donation::first()->tribute_name)->toBe('Mrs Cecilia Anyatuik Adam');
});

// ── Coming back ─────────────────────────────────────────────────────────────

it('has the callback route the env file has always pointed at', function () {
    // ⚠ `PAYSTACK_CALLBACK_URL` has said `/donate/callback` since Phase 2 and
    // the route did not exist: a real payment returned the donor to a 404.
    $this->post(route('donate.store'), donationPayload());

    $donation = Donation::first();

    $this->get(route('donate.callback', ['reference' => $donation->transaction->gateway_reference]))
        ->assertRedirect(route('donate.thanks', $donation));
});

it('sends somebody with no reference back to the form rather than to an error', function () {
    $this->get(route('donate.callback'))->assertRedirect(route('donate'));
});

it('does not believe the query string about the outcome', function () {
    /*
     * ⚠ The most important assertion in this file. The gift is still pending —
     * no webhook has arrived — and no amount of `?status=success` may make the
     * page say otherwise.
     */
    $this->post(route('donate.store'), donationPayload());

    $donation = Donation::first();

    $this->get(route('donate.thanks', $donation).'?status=success&trxref=anything')
        ->assertOk()
        ->assertSee('being confirmed')
        ->assertDontSee('has been received');
});

it('thanks the donor once the webhook says the money arrived', function () {
    $this->post(route('donate.store'), donationPayload());

    $donation = Donation::first();

    // Through the real webhook path: signature, raw event store, idempotency
    // and queued processing — the point of a fake gateway rather than a mock.
    app(FakeGateway::class)->deliverWebhook($donation->transaction);

    $this->get(route('donate.thanks', $donation->fresh()))
        ->assertOk()
        ->assertSee('has been received');

    expect($donation->fresh()->status)->toBe(DonationStatus::Completed);
});

it('says plainly when a payment did not go through', function () {
    // A foundation needs to know what a declined card looks like to the person
    // holding it, and that is not something to learn from a donor's complaint.
    $this->post(route('donate.store'), donationPayload());

    $donation = Donation::first();

    app(FakeGateway::class)->deliverWebhook($donation->transaction, successful: false);

    $this->get(route('donate.thanks', $donation->fresh()))
        ->assertOk()
        ->assertSee('Nothing has been charged');
});

// ── The sandbox checkout ────────────────────────────────────────────────────

it('has the sandbox page the fake gateway has always linked to', function () {
    /*
     * ⚠ `fake` is the DEFAULT driver and its authorization URL pointed at a
     * route nobody had built. The donation engine was fully tested and the
     * donation journey could not be walked once, by anybody.
     */
    $this->post(route('donate.store'), donationPayload());

    $transaction = PaymentTransaction::first();

    $this->get($transaction->authorization_url)
        ->assertOk()
        ->assertSee('Sandbox')
        ->assertSee($transaction->gateway_reference);
});

it('walks the whole journey from form to thank-you', function () {
    // The end-to-end path that could not be exercised before this module.
    $this->post(route('donate.store'), donationPayload());

    $transaction = PaymentTransaction::first();

    $this->post(route('payments.fake.pay', $transaction->gateway_reference), ['outcome' => 'success'])
        ->assertRedirect();

    expect(Donation::first()->status)->toBe(DonationStatus::Completed);
});

// ── Monthly giving ──────────────────────────────────────────────────────────

it('sets up a monthly gift only once the money has actually arrived', function () {
    /*
     * ⚠ `RecurringGivingService::establish()` refuses a gift that has not
     * completed, and rightly: a standing order set up from a payment that was
     * later declined is a monthly charge against a card that never worked. So
     * the intent is stored on the donation and acted on by the webhook.
     */
    $this->post(route('donate.store'), donationPayload(['frequency' => 'monthly']));

    $donation = Donation::first();

    expect($donation->wants_recurring)->toBeTrue()
        ->and(Subscription::count())->toBe(0);

    app(FakeGateway::class)->deliverWebhook($donation->transaction);

    expect(Subscription::count())->toBe(1)
        ->and($donation->fresh()->subscription_id)->not->toBeNull();
});

it('does not set one up for a gift that failed', function () {
    $this->post(route('donate.store'), donationPayload(['frequency' => 'monthly']));

    $donation = Donation::first();

    app(FakeGateway::class)->deliverWebhook($donation->transaction, successful: false);

    expect(Subscription::count())->toBe(0);
});
