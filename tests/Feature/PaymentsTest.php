<?php

declare(strict_types=1);

use App\Enums\PaymentStatus;
use App\Jobs\ProcessPaymentWebhook;
use App\Models\PaymentTransaction;
use App\Models\PaymentWebhookEvent;
use App\Models\Refund;
use App\Models\User;
use App\Payments\FakeGateway;
use App\Payments\PayloadScrubber;
use App\Payments\PaymentManager;
use App\Payments\PaystackService;
use App\ValueObjects\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The gateway boundary
|--------------------------------------------------------------------------
|
| Everything else in this application can be retried or corrected. Crediting a
| donation for money nobody paid cannot. These tests hold four lines:
|
|   1. an unsigned or wrongly signed webhook never moves money
|   2. a replayed webhook is a no-op
|   3. an amount or currency that does not match is HELD, never completed
|   4. a settled payment is never reopened by a later event
|
*/

beforeEach(function () {
    config(['payments.paystack.webhook_secret' => 'sk_test_webhook_secret_for_tests']);

    $this->payments = app(PaymentManager::class);
});

/** A Paystack-shaped charge.success body for a given transaction. */
function chargeSuccessBody(PaymentTransaction $transaction, ?int $amountMinor = null, string $currency = 'GHS'): string
{
    return json_encode([
        'event' => 'charge.success',
        'data' => [
            'id' => random_int(1_000_000, 9_999_999),
            'reference' => $transaction->gateway_reference,
            'status' => 'success',
            'amount' => $amountMinor ?? $transaction->amount->toMinor(),
            'currency' => $currency,
            'fees' => 488,
            'channel' => 'mobile_money',
            'paid_at' => now()->toIso8601String(),
            'authorization' => [
                'authorization_code' => 'AUTH_test123',
                'last4' => '4321',
                'card_type' => 'mobile_money',
                'bank' => 'MTN',
            ],
        ],
    ], JSON_THROW_ON_ERROR);
}

function postWebhook(string $body, ?string $signature = null): TestResponse
{
    return test()->call(
        'POST',
        config('payments.paystack.webhook_path'),
        [],
        [],
        [],
        ['HTTP_X_PAYSTACK_SIGNATURE' => $signature ?? FakeGateway::sign($body), 'CONTENT_TYPE' => 'application/json'],
        $body,
    );
}

// ═══════════════════════════════════════════════════════════════════════════
//  Signature verification
// ═══════════════════════════════════════════════════════════════════════════

it('accepts a correctly signed webhook', function () {
    $transaction = PaymentTransaction::factory()->create();
    $body = chargeSuccessBody($transaction);

    postWebhook($body)->assertOk();

    expect(PaymentWebhookEvent::first()->signature_valid)->toBeTrue();
});

it('stores but never processes a webhook with a bad signature', function () {
    $transaction = PaymentTransaction::factory()->create();
    $body = chargeSuccessBody($transaction);

    postWebhook($body, signature: 'not-the-right-signature')->assertOk();

    $event = PaymentWebhookEvent::first();

    expect($event->signature_valid)->toBeFalse();

    // Kept as evidence — a run of these means somebody is probing the endpoint.
    $this->payments->processWebhook($event);

    expect($transaction->fresh()->status)->toBe(PaymentStatus::Pending)
        ->and($event->fresh()->isProcessed())->toBeFalse();
});

it('rejects a webhook with no signature at all', function () {
    $transaction = PaymentTransaction::factory()->create();

    postWebhook(chargeSuccessBody($transaction), signature: '')->assertOk();

    expect(PaymentWebhookEvent::first()->signature_valid)->toBeFalse();
});

it('signs over the raw bytes, so a re-encoded body fails', function () {
    // json_encode(json_decode($body)) is not byte-identical, and the signature
    // is over the bytes. This is why the controller reads getContent().
    $transaction = PaymentTransaction::factory()->create();
    $body = chargeSuccessBody($transaction);

    $reEncoded = json_encode(json_decode($body, true), JSON_PRETTY_PRINT);

    expect(FakeGateway::sign($reEncoded))->not->toBe(FakeGateway::sign($body));
});

it('rejects everything when no webhook secret is configured', function () {
    // An unconfigured endpoint must reject, never accept.
    config(['payments.paystack.webhook_secret' => '']);

    expect(app(PaystackService::class)->verifySignature('{}', 'anything'))->toBeFalse();
});

it('answers 200 even to a forged request', function () {
    // Paystack retries anything that is not 2xx, so a 401 turns a probe into a
    // retry storm and buys nothing.
    postWebhook('{"event":"charge.success"}', signature: 'forged')->assertOk();
});

// ═══════════════════════════════════════════════════════════════════════════
//  Storing before understanding
// ═══════════════════════════════════════════════════════════════════════════

it('stores the raw payload verbatim, before parsing', function () {
    $transaction = PaymentTransaction::factory()->create();
    $body = chargeSuccessBody($transaction);

    postWebhook($body);

    expect(PaymentWebhookEvent::first()->raw_payload)->toBe($body);
});

it('stores a malformed payload rather than throwing it away', function () {
    // A malformed webhook is still evidence — of an integration change, a bug,
    // or an attack.
    postWebhook('{"event": "charge.success", this is not json');

    expect(PaymentWebhookEvent::count())->toBe(1)
        ->and(PaymentWebhookEvent::first()->payload())->toBe([]);
});

it('refuses to alter a stored event afterwards', function () {
    postWebhook('{"event":"charge.success","data":{"id":1}}');

    $event = PaymentWebhookEvent::first();
    $event->update(['raw_payload' => 'rewritten']);
})->throws(RuntimeException::class, 'append-only');

it('refuses to delete a stored event', function () {
    postWebhook('{"event":"charge.success","data":{"id":1}}');

    PaymentWebhookEvent::first()->delete();
})->throws(RuntimeException::class, 'never deleted');

// ═══════════════════════════════════════════════════════════════════════════
//  Replay
// ═══════════════════════════════════════════════════════════════════════════

it('treats a replayed webhook as a no-op', function () {
    $transaction = PaymentTransaction::factory()->create();
    $body = chargeSuccessBody($transaction);

    postWebhook($body);
    postWebhook($body);
    postWebhook($body);

    // The unique index on event_id is the guarantee, at the database level
    // rather than in application logic that has to be right every time.
    expect(PaymentWebhookEvent::count())->toBe(1);
});

it('does not double-count a donation when the same event is processed twice', function () {
    $transaction = PaymentTransaction::factory()->create();
    postWebhook(chargeSuccessBody($transaction));

    $event = PaymentWebhookEvent::first();

    $this->payments->processWebhook($event);
    $this->payments->processWebhook($event->fresh());

    $transaction->refresh();

    expect($transaction->status)->toBe(PaymentStatus::Success)
        ->and($transaction->amount_paid_minor)->toBe(25_000);
});

it('gives an event with no id a stable identity from its body', function () {
    // Without this, an id-less event would insert a fresh row on every delivery
    // and be processed every time.
    $body = '{"event":"charge.success","data":{"reference":"X"}}';

    postWebhook($body);
    postWebhook($body);

    expect(PaymentWebhookEvent::count())->toBe(1);
});

// ═══════════════════════════════════════════════════════════════════════════
//  Amount and currency verification — the money rules
// ═══════════════════════════════════════════════════════════════════════════

it('completes a payment whose amount matches exactly', function () {
    $transaction = PaymentTransaction::factory()->create();
    postWebhook(chargeSuccessBody($transaction));

    $this->payments->processWebhook(PaymentWebhookEvent::first());

    expect($transaction->fresh()->status)->toBe(PaymentStatus::Success);
});

it('holds a short payment for review rather than completing it', function () {
    $transaction = PaymentTransaction::factory()->create();

    // One pesewa light. Not "close enough" — a difference is a difference.
    postWebhook(chargeSuccessBody($transaction, amountMinor: 24_999));

    $this->payments->processWebhook(PaymentWebhookEvent::first());

    $transaction->refresh();

    expect($transaction->status)->toBe(PaymentStatus::Mismatch)
        ->and($transaction->status->needsReview())->toBeTrue()
        ->and($transaction->mismatch_reason)->toContain('Amount mismatch')
        // Emphatically not failed: the money may well have been taken.
        ->and($transaction->status)->not->toBe(PaymentStatus::Failed);
});

it('holds an overpayment for review too', function () {
    $transaction = PaymentTransaction::factory()->create();

    postWebhook(chargeSuccessBody($transaction, amountMinor: 30_000));

    $this->payments->processWebhook(PaymentWebhookEvent::first());

    expect($transaction->fresh()->status)->toBe(PaymentStatus::Mismatch);
});

it('holds a payment that settles in the wrong currency', function () {
    $transaction = PaymentTransaction::factory()->create();

    postWebhook(chargeSuccessBody($transaction, currency: 'NGN'));

    $this->payments->processWebhook(PaymentWebhookEvent::first());

    $transaction->refresh();

    expect($transaction->status)->toBe(PaymentStatus::Mismatch)
        ->and($transaction->mismatch_reason)->toContain('Currency mismatch');
});

it('records what was actually paid even when it does not match', function () {
    // Finance needs both numbers to work out what happened.
    $transaction = PaymentTransaction::factory()->create();

    postWebhook(chargeSuccessBody($transaction, amountMinor: 24_999));
    $this->payments->processWebhook(PaymentWebhookEvent::first());

    $transaction->refresh();

    expect($transaction->amount)->toEqualPesewas(25_000)
        ->and($transaction->amount_paid_minor)->toBe(24_999);
});

it('stores the fee the gateway actually charged, not our model of it', function () {
    $transaction = PaymentTransaction::factory()->create();

    postWebhook(chargeSuccessBody($transaction));
    $this->payments->processWebhook(PaymentWebhookEvent::first());

    // 488 came from the payload. Our FeeCalculator would say 488 too at 1.95%
    // of GH₵ 250 — but reconciliation compares them, and the gateway wins.
    expect($transaction->fresh()->fee_minor)->toBe(488);
});

// ═══════════════════════════════════════════════════════════════════════════
//  Finality
// ═══════════════════════════════════════════════════════════════════════════

it('never lets a late failure erase a completed payment', function () {
    $transaction = PaymentTransaction::factory()->settled()->create();

    $transaction->markFailed('A charge.failed arriving after the success.');

    // Otherwise a completed gift silently disappears from a donor's history.
    expect($transaction->fresh()->status)->toBe(PaymentStatus::Success);
});

it('never re-settles an already settled payment', function () {
    $transaction = PaymentTransaction::factory()->settled()->create();

    $status = $transaction->settle(Money::ofMinor(999_999));

    expect($status)->toBe(PaymentStatus::Success)
        ->and($transaction->fresh()->amount_paid_minor)->toBe(25_000);
});

it('does not abandon a payment that already failed', function () {
    $transaction = PaymentTransaction::factory()->failed()->create();

    $transaction->markAbandoned();

    expect($transaction->fresh()->status)->toBe(PaymentStatus::Failed);
});

// ═══════════════════════════════════════════════════════════════════════════
//  Card data — the SAQ-A posture
// ═══════════════════════════════════════════════════════════════════════════

it('refuses to store anything longer than four card digits', function () {
    $transaction = PaymentTransaction::factory()->create();

    $transaction->recordInstrument(['last4' => '4111111111111111']);
})->throws(RuntimeException::class, 'never receives or stores card data');

it('scrubs card-shaped values from a payload at any depth', function () {
    $scrubbed = app(PayloadScrubber::class)->scrub([
        'data' => [
            'authorization' => ['card' => 'x', 'cvv' => '123', 'last4' => '4321'],
            'note' => '4111 1111 1111 1111',
        ],
    ]);

    expect($scrubbed['data']['authorization']['card'])->toBe(PayloadScrubber::REDACTED)
        ->and($scrubbed['data']['authorization']['cvv'])->toBe(PayloadScrubber::REDACTED)
        // A value that looks like a PAN goes whatever its key is — the
        // dangerous case is the field nobody anticipated.
        ->and($scrubbed['data']['note'])->toBe(PayloadScrubber::REDACTED)
        // Last four is metadata, not card data, and is needed on a receipt.
        ->and($scrubbed['data']['authorization']['last4'])->toBe('4321');
});

it('keeps only allow-listed authorization fields', function () {
    $transaction = PaymentTransaction::factory()->create();
    postWebhook(chargeSuccessBody($transaction));
    $this->payments->processWebhook(PaymentWebhookEvent::first());

    $transaction->refresh();

    expect($transaction->card_last4)->toBe('4321')
        ->and($transaction->authorization_code)->toBe('AUTH_test123')
        ->and($transaction->bank)->toBe('MTN');
});

// ═══════════════════════════════════════════════════════════════════════════
//  Queueing
// ═══════════════════════════════════════════════════════════════════════════

it('queues the work rather than doing it in the request', function () {
    Queue::fake();

    $transaction = PaymentTransaction::factory()->create();
    postWebhook(chargeSuccessBody($transaction))->assertOk();

    // Paystack times out on a slow endpoint and retries, which is how one slow
    // receipt render becomes four duplicate deliveries.
    Queue::assertPushed(ProcessPaymentWebhook::class);
});

it('does not queue work for an unsigned webhook', function () {
    Queue::fake();

    postWebhook('{"event":"charge.success","data":{"id":9}}', signature: 'forged');

    Queue::assertNothingPushed();
});

it('acknowledges an event type it does not handle without acting on it', function () {
    // Paystack adds event types without warning; failing on them would fill the
    // queue with noise.
    $body = json_encode(['event' => 'customer.identification.success', 'data' => ['id' => 42]]);

    postWebhook($body);
    $event = PaymentWebhookEvent::first();

    $this->payments->processWebhook($event);

    expect($event->fresh()->isProcessed())->toBeTrue();
});

it('ignores a webhook for a reference it has never seen', function () {
    // Usually a dashboard test event, or another integration on the same
    // merchant account.
    $body = json_encode([
        'event' => 'charge.success',
        'data' => ['id' => 55, 'reference' => 'UNKNOWN-REF', 'status' => 'success', 'amount' => 100, 'currency' => 'GHS'],
    ]);

    postWebhook($body);
    $this->payments->processWebhook(PaymentWebhookEvent::first());

    expect(PaymentWebhookEvent::first()->fresh()->isProcessed())->toBeTrue();
});

// ═══════════════════════════════════════════════════════════════════════════
//  Refunds
// ═══════════════════════════════════════════════════════════════════════════

it('refuses to refund a payment that never settled', function () {
    $transaction = PaymentTransaction::factory()->create();

    Refund::create([
        'payment_transaction_id' => $transaction->id,
        'amount' => 1_000,
        'reason' => 'Donor asked.',
    ]);
})->throws(RuntimeException::class, 'Only a settled payment can be refunded');

it('refuses to refund more than was taken', function () {
    $transaction = PaymentTransaction::factory()->settled()->create();

    Refund::create([
        'payment_transaction_id' => $transaction->id,
        'amount' => 30_000,
        'reason' => 'Donor asked.',
    ]);
})->throws(RuntimeException::class, 'remains refundable');

it('counts earlier refunds against what is left', function () {
    $transaction = PaymentTransaction::factory()->settled()->create();

    Refund::create([
        'payment_transaction_id' => $transaction->id,
        'amount' => 20_000,
        'reason' => 'Partial refund.',
        'status' => Refund::STATUS_PROCESSED,
    ]);

    expect($transaction->fresh()->refundableAmount())->toEqualPesewas(5_000);

    Refund::create([
        'payment_transaction_id' => $transaction->id,
        'amount' => 10_000,
        'reason' => 'Too much.',
    ]);
})->throws(RuntimeException::class, 'remains refundable');

it('insists on a stated reason', function () {
    // Money leaving the foundation, and an auditor will ask why.
    $transaction = PaymentTransaction::factory()->settled()->create();

    Refund::create([
        'payment_transaction_id' => $transaction->id,
        'amount' => 1_000,
        'reason' => '',
    ]);
})->throws(RuntimeException::class, 'needs a stated reason');

it('will not let one person both request and approve a refund', function () {
    // The standard control on an outbound payment. A system that allows one
    // person to do both has no control at all.
    $staff = User::factory()->staff()->create();
    $transaction = PaymentTransaction::factory()->settled()->create();

    $refund = Refund::create([
        'payment_transaction_id' => $transaction->id,
        'amount' => 1_000,
        'reason' => 'Donor asked.',
        'requested_by' => $staff->id,
    ]);

    $refund->approve($staff);
})->throws(RuntimeException::class, 'cannot be approved by the person who requested it');

it('accepts approval from a second person', function () {
    $requester = User::factory()->staff()->create();
    $approver = User::factory()->staff()->create();
    $transaction = PaymentTransaction::factory()->settled()->create();

    $refund = Refund::create([
        'payment_transaction_id' => $transaction->id,
        'amount' => 1_000,
        'reason' => 'Donor asked.',
        'requested_by' => $requester->id,
    ]);

    $refund->approve($approver);

    expect($refund->fresh()->status)->toBe(Refund::STATUS_PENDING)
        ->and($refund->fresh()->isApproved())->toBeTrue();
});

// ═══════════════════════════════════════════════════════════════════════════
//  Driver safety
// ═══════════════════════════════════════════════════════════════════════════

it('uses the fake gateway while no merchant account exists', function () {
    expect($this->payments->gateway())->toBeInstanceOf(FakeGateway::class);
});

it('verifies signatures even on the fake gateway', function () {
    // Faking this to always pass would leave the most security-critical line in
    // the module untested, which defeats having a fake at all.
    $gateway = app(FakeGateway::class);

    expect($gateway->verifySignature('body', FakeGateway::sign('body')))->toBeTrue()
        ->and($gateway->verifySignature('body', 'wrong'))->toBeFalse();
});

it('marks stale open transactions as abandoned candidates', function () {
    PaymentTransaction::factory()->create(['created_at' => now()->subHours(3)]);
    PaymentTransaction::factory()->create();

    expect(PaymentTransaction::query()->stale()->count())->toBe(1);
});
