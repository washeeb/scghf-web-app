<?php

declare(strict_types=1);

use App\Models\PaymentTransaction;
use App\Models\Refund;
use App\Models\User;
use App\Payments\PayloadScrubber;
use App\Payments\PaystackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 14 — the real Paystack client, against a faked HTTP layer
|--------------------------------------------------------------------------
|
| PaymentsTest holds the webhook line with the fake gateway. Nothing until
| now exercised the class that actually talks to Paystack: what it puts on
| the wire (the amount in pesewas, the currency on every call, the bearer
| token), and how it reads what comes back (Paystack's amount, not ours;
| the allow-listed authorisation; a scrubbed raw payload). A wrong request
| body here is money sent for the wrong amount; a wrong parser is a donor
| credited for money nobody paid. So every endpoint the service calls is
| covered, success and refusal both, with the request captured.
|
*/

beforeEach(function () {
    config([
        'payments.paystack.secret_key' => 'sk_test_0123456789abcdef',
        'payments.paystack.base_url' => 'https://api.paystack.co',
        'payments.paystack.callback_url' => 'https://example.test/donate/return',
        'payments.paystack.channels' => ['card', 'mobile_money'],
    ]);

    $this->service = app(PaystackService::class);
});

/** The one request the fake received. */
function paystackRequest(): Request
{
    $sent = Http::recorded()->map(fn (array $pair): Request => $pair[0]);

    expect($sent)->toHaveCount(1);

    return $sent->first();
}

/** A Paystack transaction object as /transaction/verify and /charge return it. */
function paystackTransaction(PaymentTransaction $transaction, array $overrides = []): array
{
    return array_replace([
        'id' => 4_512_998,
        'status' => 'success',
        'reference' => $transaction->gateway_reference,
        'amount' => $transaction->amount->toMinor(),
        'currency' => 'GHS',
        'fees' => 488,
        'channel' => 'mobile_money',
        'gateway_response' => 'Approved',
        'paid_at' => '2026-09-17T10:15:00.000Z',
        'authorization' => [
            'authorization_code' => 'AUTH_8dfhjs9',
            'bin' => '408408',
            'last4' => '4081',
            'exp_month' => '12',
            'exp_year' => '2030',
            'channel' => 'card',
            'card_type' => 'visa',
            'bank' => 'TEST BANK',
            'country_code' => 'GH',
            'brand' => 'visa',
            'reusable' => true,
            'signature' => 'SIG_secret',
            'account_name' => 'Ama Mensah',
        ],
    ], $overrides);
}

// ── initialise ──────────────────────────────────────────────────────────────

it('initialises with the amount in pesewas, the currency, and the secret as a bearer token', function () {
    Http::fake([
        'api.paystack.co/transaction/initialize' => Http::response([
            'status' => true,
            'message' => 'Authorization URL created',
            'data' => [
                'authorization_url' => 'https://checkout.paystack.com/abc123',
                'access_code' => 'abc123',
                'reference' => 'ref-from-paystack',
            ],
        ]),
    ]);

    $transaction = PaymentTransaction::factory()->create(['amount' => 5_000, 'customer_email' => 'ama@example.test']);

    $result = $this->service->initialise($transaction, ['metadata' => ['donation_id' => 7]]);

    $request = paystackRequest();

    expect($request->method())->toBe('POST')
        ->and($request->url())->toBe('https://api.paystack.co/transaction/initialize')
        ->and($request->header('Authorization'))->toBe(['Bearer sk_test_0123456789abcdef'])
        ->and($request->header('Accept'))->toBe(['application/json'])
        ->and($request['amount'])->toBe(5_000)
        ->and($request['currency'])->toBe('GHS')
        ->and($request['email'])->toBe('ama@example.test')
        ->and($request['reference'])->toBe($transaction->gateway_reference)
        ->and($request['callback_url'])->toBe('https://example.test/donate/return')
        ->and($request['channels'])->toBe(['card', 'mobile_money'])
        ->and($request['metadata'])->toBe(['donation_id' => 7]);

    expect($result->successful)->toBeTrue()
        ->and($result->status)->toBe('initialised')
        ->and($result->authorizationUrl)->toBe('https://checkout.paystack.com/abc123')
        ->and($result->accessCode)->toBe('abc123')
        ->and($result->gatewayReference)->toBe('ref-from-paystack');
});

it('sends the amount as an integer even when the money is a round cedi figure', function () {
    // GH₵ 250.00 is 25000, not 250 and not 250.0. The integer on the wire is
    // the whole point of storing pesewas.
    Http::fake(['api.paystack.co/*' => Http::response(['status' => true, 'data' => []])]);

    $this->service->initialise(PaymentTransaction::factory()->create(['amount' => 25_000]));

    $body = json_decode(paystackRequest()->body(), true, flags: JSON_THROW_ON_ERROR);

    expect($body['amount'])->toBeInt()->toBe(25_000)
        ->and(paystackRequest()->body())->toContain('"amount":25000');
});

it('reads a refused initialisation as a failure carrying Paystack\'s own words', function () {
    Http::fake([
        'api.paystack.co/transaction/initialize' => Http::response([
            'status' => false,
            'message' => 'Invalid key',
        ], 401),
    ]);

    $transaction = PaymentTransaction::factory()->create();
    $result = $this->service->initialise($transaction);

    expect($result->successful)->toBeFalse()
        ->and($result->status)->toBe('failed')
        ->and($result->message)->toBe('Invalid key')
        ->and($result->gatewayReference)->toBe($transaction->gateway_reference)
        ->and($result->authorizationUrl)->toBeNull();
});

it('treats a 200 with status:false as a refusal, not a success', function () {
    // Paystack answers some refusals with HTTP 200 and status:false in the body.
    // An HTTP-only check would send the donor to a checkout that does not exist.
    Http::fake([
        'api.paystack.co/transaction/initialize' => Http::response(['status' => false, 'message' => 'Currency not supported by merchant'], 200),
    ]);

    $result = $this->service->initialise(PaymentTransaction::factory()->create());

    expect($result->successful)->toBeFalse()->and($result->message)->toBe('Currency not supported by merchant');
});

it('retries once on a gateway error before giving up', function () {
    Http::fakeSequence('api.paystack.co/transaction/initialize')
        ->push(['status' => false, 'message' => 'Server error'], 502)
        ->push(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/retry', 'access_code' => 'r1', 'reference' => 'r1']]);

    $result = $this->service->initialise(PaymentTransaction::factory()->create());

    expect($result->successful)->toBeTrue()->and($result->authorizationUrl)->toBe('https://checkout.paystack.com/retry');
    Http::assertSentCount(2);
});

it('reports a gateway that stays down as a failure rather than throwing at the donor', function () {
    Http::fake(['api.paystack.co/*' => Http::response(['status' => false, 'message' => 'Still down'], 503)]);

    $result = $this->service->initialise(PaymentTransaction::factory()->create());

    expect($result->successful)->toBeFalse()->and($result->message)->toBe('Still down');
    Http::assertSentCount(2);
});

it('refuses to guess a secret key and makes no request without one', function () {
    config(['payments.paystack.secret_key' => '']);
    Http::fake();

    expect(fn () => $this->service->initialise(PaymentTransaction::factory()->create()))
        ->toThrow(RuntimeException::class, 'PAYSTACK_SECRET_KEY is not set');

    Http::assertNothingSent();
});

// ── verify ──────────────────────────────────────────────────────────────────

it('verifies by reference and takes the amount, fee and channel from Paystack, not from us', function () {
    $transaction = PaymentTransaction::factory()->create(['amount' => 5_000]);

    Http::fake([
        'api.paystack.co/transaction/verify/*' => Http::response([
            'status' => true,
            'message' => 'Verification successful',
            'data' => paystackTransaction($transaction, ['amount' => 4_999, 'fees' => 97]),
        ]),
    ]);

    $result = $this->service->verify($transaction->gateway_reference);

    $request = paystackRequest();
    expect($request->method())->toBe('GET')
        ->and($request->url())->toBe('https://api.paystack.co/transaction/verify/'.$transaction->gateway_reference);

    expect($result->isSuccess())->toBeTrue()
        ->and($result->amount)->toEqualPesewas(4_999)
        ->and($result->amount->currency)->toBe('GHS')
        ->and($result->fee)->toEqualPesewas(97)
        ->and($result->channel)->toBe('mobile_money')
        ->and($result->paidAt?->toIso8601String())->toBe('2026-09-17T10:15:00+00:00')
        ->and($result->gatewayReference)->toBe($transaction->gateway_reference);
});

it('url-encodes the reference it verifies', function () {
    Http::fake(['api.paystack.co/*' => Http::response(['status' => true, 'data' => ['status' => 'success', 'amount' => 1, 'currency' => 'GHS']])]);

    $this->service->verify('ref with space/and-slash');

    expect(paystackRequest()->url())->toBe('https://api.paystack.co/transaction/verify/ref+with+space%2Fand-slash');
});

it('keeps only the allow-listed authorisation fields and never the card BIN or signature', function () {
    $transaction = PaymentTransaction::factory()->create();

    Http::fake(['api.paystack.co/*' => Http::response(['status' => true, 'data' => paystackTransaction($transaction)])]);

    $result = $this->service->verify($transaction->gateway_reference);

    expect($result->authorization)->toBe([
        'authorization_code' => 'AUTH_8dfhjs9',
        'last4' => '4081',
        'exp_month' => '12',
        'exp_year' => '2030',
        'channel' => 'card',
        'card_type' => 'visa',
        'bank' => 'TEST BANK',
        'country_code' => 'GH',
        'brand' => 'visa',
        'reusable' => true,
    ])
        ->and($result->authorization)->not->toHaveKeys(['bin', 'signature', 'account_name']);
});

it('reports an unpaid or abandoned verification as a failure with the gateway\'s reason', function (string $status, string $reason) {
    $transaction = PaymentTransaction::factory()->create();

    Http::fake(['api.paystack.co/*' => Http::response([
        'status' => true,
        'data' => paystackTransaction($transaction, ['status' => $status, 'gateway_response' => $reason]),
    ])]);

    $result = $this->service->verify($transaction->gateway_reference);

    expect($result->successful)->toBeFalse()
        ->and($result->status)->toBe($status)
        ->and($result->message)->toBe($reason)
        ->and($result->amount)->toBeNull();
})->with([
    ['abandoned', 'The transaction was not completed'],
    ['failed', 'Declined'],
    ['ongoing', 'Pending'],
]);

it('reports an unknown reference as a failure', function () {
    Http::fake(['api.paystack.co/*' => Http::response(['status' => false, 'message' => 'Transaction reference not found'], 404)]);

    $result = $this->service->verify('nope');

    expect($result->successful)->toBeFalse()
        ->and($result->message)->toBe('Transaction reference not found')
        ->and($result->gatewayReference)->toBe('nope');
});

it('scrubs card-shaped keys out of the raw payload it keeps', function () {
    $transaction = PaymentTransaction::factory()->create();

    Http::fake(['api.paystack.co/*' => Http::response(['status' => true, 'data' => paystackTransaction($transaction, [
        'authorization' => ['authorization_code' => 'AUTH_x', 'card_number' => '4084084084084081', 'cvv' => '408', 'pin' => '1234', 'last4' => '4081'],
    ])])]);

    $result = $this->service->verify($transaction->gateway_reference);

    $auth = $result->raw['data']['authorization'];
    expect($auth['card_number'])->toBe(PayloadScrubber::REDACTED)
        ->and($auth['cvv'])->toBe(PayloadScrubber::REDACTED)
        ->and($auth['pin'])->toBe(PayloadScrubber::REDACTED)
        ->and($auth['last4'])->toBe('4081')
        ->and(json_encode($result->raw))->not->toContain('4084084084084081');
});

// ── chargeAuthorization (recurring gifts) ───────────────────────────────────

it('charges a stored authorisation with the currency and reads it through the verify parser', function () {
    $transaction = PaymentTransaction::factory()->create(['amount' => 2_000, 'customer_email' => 'kofi@example.test']);

    Http::fake(['api.paystack.co/transaction/charge_authorization' => Http::response([
        'status' => true,
        'data' => paystackTransaction($transaction, ['amount' => 2_000, 'channel' => 'card', 'fees' => 39]),
    ])]);

    $result = $this->service->chargeAuthorization($transaction, 'AUTH_8dfhjs9');

    $request = paystackRequest();
    expect($request->url())->toBe('https://api.paystack.co/transaction/charge_authorization')
        ->and($request['authorization_code'])->toBe('AUTH_8dfhjs9')
        ->and($request['email'])->toBe('kofi@example.test')
        ->and($request['amount'])->toBe(2_000)
        ->and($request['currency'])->toBe('GHS')
        ->and($request['reference'])->toBe($transaction->gateway_reference);

    expect($result->isSuccess())->toBeTrue()
        ->and($result->amount)->toEqualPesewas(2_000)
        ->and($result->fee)->toEqualPesewas(39)
        ->and($result->authorization['authorization_code'])->toBe('AUTH_8dfhjs9');
});

it('reports a declined stored authorisation as a failure and never as a pending charge', function () {
    $transaction = PaymentTransaction::factory()->create();

    Http::fake(['api.paystack.co/*' => Http::response(['status' => true, 'data' => paystackTransaction($transaction, ['status' => 'failed', 'gateway_response' => 'Insufficient Funds'])])]);

    $result = $this->service->chargeAuthorization($transaction, 'AUTH_dead');

    expect($result->successful)->toBeFalse()
        ->and($result->isPending())->toBeFalse()
        ->and($result->message)->toBe('Insufficient Funds');
});

it('reports an invalid stored authorisation in Paystack\'s words', function () {
    Http::fake(['api.paystack.co/*' => Http::response(['status' => false, 'message' => 'Authorization code is invalid'], 400)]);

    $result = $this->service->chargeAuthorization(PaymentTransaction::factory()->create(), 'AUTH_dead');

    expect($result->successful)->toBeFalse()->and($result->message)->toBe('Authorization code is invalid');
});

// ── chargeMobileMoney and submitOtp ─────────────────────────────────────────

it('pushes a mobile-money prompt with the transaction\'s amount and reports what Paystack is waiting for', function (string $status, string $text) {
    $transaction = PaymentTransaction::factory()->create(['amount' => 1_500, 'request_payload' => ['donation_id' => 3]]);

    Http::fake(['api.paystack.co/charge' => Http::response([
        'status' => true,
        'message' => 'Charge attempted',
        'data' => ['reference' => $transaction->gateway_reference, 'status' => $status, 'display_text' => $text],
    ])]);

    $result = $this->service->chargeMobileMoney($transaction, 'mtn', '+233241234567');

    $request = paystackRequest();
    expect($request->url())->toBe('https://api.paystack.co/charge')
        ->and($request['amount'])->toBe(1_500)
        ->and($request['currency'])->toBe('GHS')
        ->and($request['reference'])->toBe($transaction->gateway_reference)
        ->and($request['mobile_money'])->toBe(['phone' => '+233241234567', 'provider' => 'mtn'])
        ->and($request['metadata'])->toBe(['donation_id' => 3]);

    expect($result->successful)->toBeTrue()
        ->and($result->isSuccess())->toBeFalse()
        ->and($result->isPending())->toBeTrue()
        ->and($result->status)->toBe($status)
        ->and($result->message)->toBe($text);
})->with([
    ['pay_offline', 'Please approve the prompt on your phone'],
    ['send_otp', 'Please enter the voucher code sent to your phone'],
    ['pending', 'Waiting'],
]);

it('settles a mobile-money charge that succeeds straight away through the same parser', function () {
    $transaction = PaymentTransaction::factory()->create(['amount' => 1_500]);

    Http::fake(['api.paystack.co/charge' => Http::response(['status' => true, 'data' => paystackTransaction($transaction, ['amount' => 1_500, 'fees' => 29])])]);

    $result = $this->service->chargeMobileMoney($transaction, 'mtn', '+233241234567');

    expect($result->isSuccess())->toBeTrue()->and($result->amount)->toEqualPesewas(1_500)->and($result->fee)->toEqualPesewas(29);
});

it('reports a refused mobile-money charge in the network\'s words', function (array $body, int $status, bool $expectMessage) {
    Http::fake(['api.paystack.co/charge' => Http::response($body, $status)]);

    $result = $this->service->chargeMobileMoney(PaymentTransaction::factory()->create(), 'mtn', '+233241234567');

    expect($result->successful)->toBeFalse()->and($result->isPending())->toBeFalse();

    if ($expectMessage) {
        expect($result->message)->toBe($body['data']['gateway_response'] ?? $body['message']);
    }
})->with([
    'the wallet refused' => [['status' => true, 'data' => ['status' => 'failed', 'gateway_response' => 'Insufficient balance in wallet']], 200, true],
    'Paystack refused the request' => [['status' => false, 'message' => 'Invalid phone number'], 400, true],
    // An empty status is a shape we do not understand: refused, not pending.
    'an answer with no status' => [['status' => true, 'data' => ['reference' => 'x']], 200, false],
]);

it('submits a Telecel voucher against the reference and scrubs the code from what it keeps', function () {
    $transaction = PaymentTransaction::factory()->create(['amount' => 1_500]);

    Http::fake(['api.paystack.co/charge/submit_otp' => Http::response([
        'status' => true,
        'data' => paystackTransaction($transaction, ['amount' => 1_500, 'otp' => '123456']),
    ])]);

    $result = $this->service->submitOtp($transaction, '123456');

    $request = paystackRequest();
    expect($request->url())->toBe('https://api.paystack.co/charge/submit_otp')
        ->and($request['otp'])->toBe('123456')
        ->and($request['reference'])->toBe($transaction->gateway_reference);

    expect($result->isSuccess())->toBeTrue()
        ->and($result->raw['data']['otp'])->toBe(PayloadScrubber::REDACTED);
});

// ── refund ──────────────────────────────────────────────────────────────────

it('refunds by the original reference with the amount in pesewas and the currency', function () {
    $transaction = PaymentTransaction::factory()->settled()->create(['amount' => 25_000]);
    $refund = Refund::create([
        'payment_transaction_id' => $transaction->id,
        'amount' => 1_000,
        'reason' => 'Duplicate gift.',
        'requested_by' => User::factory()->staff()->create()->id,
    ]);

    Http::fake(['api.paystack.co/refund' => Http::response([
        'status' => true,
        'message' => 'Refund has been queued for processing',
        'data' => ['id' => 998877, 'status' => 'pending', 'amount' => 1_000, 'currency' => 'GHS'],
    ])]);

    $result = $this->service->refund($refund);

    $request = paystackRequest();
    expect($request->url())->toBe('https://api.paystack.co/refund')
        ->and($request['transaction'])->toBe($transaction->gateway_reference)
        ->and($request['amount'])->toBe(1_000)
        ->and($request['currency'])->toBe('GHS')
        ->and($request['merchant_note'])->toBe('Duplicate gift.');

    expect($result->successful)->toBeTrue()
        ->and($result->gatewayReference)->toBe('998877')
        ->and($result->amount)->toEqualPesewas(1_000);
});

it('reports a refund Paystack refuses without pretending it was queued', function () {
    $transaction = PaymentTransaction::factory()->settled()->create();
    $refund = Refund::create([
        'payment_transaction_id' => $transaction->id,
        'amount' => 1_000,
        'reason' => 'Asked.',
        'requested_by' => User::factory()->staff()->create()->id,
    ]);

    Http::fake(['api.paystack.co/refund' => Http::response(['status' => false, 'message' => 'Transaction has been fully refunded'], 400)]);

    $result = $this->service->refund($refund);

    expect($result->successful)->toBeFalse()->and($result->message)->toBe('Transaction has been fully refunded');
});

// ── the client itself ───────────────────────────────────────────────────────

it('talks to the configured base URL with JSON both ways', function () {
    config(['payments.paystack.base_url' => 'https://paystack.example.test/v9']);
    Http::fake(['paystack.example.test/*' => Http::response(['status' => true, 'data' => []])]);

    app(PaystackService::class)->initialise(PaymentTransaction::factory()->create());

    $request = paystackRequest();
    expect($request->url())->toBe('https://paystack.example.test/v9/transaction/initialize')
        ->and($request->hasHeader('Content-Type', 'application/json'))->toBeTrue()
        ->and($request->hasHeader('Accept', 'application/json'))->toBeTrue();

    Http::assertSent(fn (Request $r, $response): bool => $r->toPsrRequest()->getUri()->getHost() === 'paystack.example.test');
});
