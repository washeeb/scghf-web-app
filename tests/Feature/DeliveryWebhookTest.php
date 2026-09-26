<?php

declare(strict_types=1);

use App\Communications\DeliveryEventProcessor;
use App\Models\EmailLog;
use App\Models\InboundWebhookEvent;
use App\Models\SmsLog;
use App\Models\Suppression;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Bounces, complaints and delivery reports coming back in
|--------------------------------------------------------------------------
|
| Module 7 built a suppression list, gave EmailLog a markBounced() and a
| markComplained(), and made the whole channel depend on bounces reaching it.
| Then nothing was built to receive them. The list would have stayed empty,
| the bounce rate would have climbed, and the first thing to stop being
| delivered would have been donation receipts.
|
| The other half of this file is about the opposite risk. A bounce SUPPRESSES
| an address. If an unsigned request could produce one, anybody who could
| guess a donor's email could stop their receipts arriving — silently, and
| with no way for the donor to tell.
|
*/

beforeEach(function () {
    config()->set('communications.webhooks.providers.postmark', [
        'channel' => 'email',
        'secret' => 'test-secret',
        'signature_header' => 'x-postmark-signature',
        'algorithm' => 'sha256',
    ]);
});

function postDelivery(string $provider, array $payload, ?string $secret = 'test-secret'): TestResponse
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR);

    $headers = $secret === null
        ? []
        : ['x-postmark-signature' => hash_hmac('sha256', $body, $secret)];

    return test()->call(
        'POST',
        '/webhooks/delivery/'.$provider,
        [], [], [],
        collect($headers)->mapWithKeys(fn ($v, $k) => ['HTTP_'.strtoupper(str_replace('-', '_', $k)) => $v])->all()
        + ['CONTENT_TYPE' => 'application/json'],
        $body,
    );
}

// ── Authentication ──────────────────────────────────────────────────────────

it('stores an unsigned event and refuses to act on it', function () {
    // The denial-of-service this prevents: suppress any address you can guess.
    postDelivery('postmark', [
        'RecordType' => 'Bounce',
        'Email' => 'donor@example.com',
        'id' => 'evt-1',
    ], secret: null)->assertOk();

    $event = InboundWebhookEvent::first();

    expect($event)->not->toBeNull()
        ->and($event->signature_valid)->toBeFalse();

    app(DeliveryEventProcessor::class)->process($event);

    expect(Suppression::count())->toBe(0)
        ->and($event->fresh()->isProcessed())->toBeFalse();
});

it('refuses a signature computed with the wrong secret', function () {
    postDelivery('postmark', ['RecordType' => 'Bounce', 'Email' => 'a@example.com', 'id' => 'e2'],
        secret: 'not-the-secret')->assertOk();

    expect(InboundWebhookEvent::first()->signature_valid)->toBeFalse();
});

it('fails closed when no secret is configured', function () {
    // The tempting shortcut — "no secret, so skip the check" — turns an
    // unconfigured endpoint into an open one.
    config()->set('communications.webhooks.providers.postmark.secret', null);

    postDelivery('postmark', ['RecordType' => 'Bounce', 'Email' => 'a@example.com', 'id' => 'e3'])
        ->assertOk();

    expect(InboundWebhookEvent::first()->signature_valid)->toBeFalse();
});

it('answers 200 to a forged request rather than inviting a retry storm', function () {
    // Providers retry anything that is not a 2xx. Answering 401 buys nothing
    // and turns a probe into a flood.
    postDelivery('postmark', ['RecordType' => 'Bounce', 'id' => 'e4'], secret: null)->assertOk();
});

it('does not pretend to be an endpoint for a provider we do not use', function () {
    postDelivery('some-other-service', ['id' => 'e5'])->assertNotFound();

    expect(InboundWebhookEvent::count())->toBe(0);
});

// ── Storing ─────────────────────────────────────────────────────────────────

it('stores the raw body exactly as it arrived', function () {
    // A payload we could not parse is the one most worth keeping: it is either
    // a provider change or somebody probing, and both need a person to look.
    postDelivery('postmark', ['RecordType' => 'Bounce', 'Email' => 'a@example.com', 'id' => 'e6']);

    expect(InboundWebhookEvent::first()->raw_payload)->toContain('"RecordType":"Bounce"');
});

it('treats a redelivery as the same event', function () {
    // Providers redeliver aggressively. A second row would be a second
    // suppression and a double-counted bounce.
    $payload = ['RecordType' => 'Bounce', 'Email' => 'a@example.com', 'id' => 'evt-repeat'];

    postDelivery('postmark', $payload);
    postDelivery('postmark', $payload);

    expect(InboundWebhookEvent::count())->toBe(1);
});

it('still deduplicates when the provider sends no id', function () {
    // A hash of the body stands in, so a replayed identical body still collides.
    $payload = ['RecordType' => 'Bounce', 'Email' => 'a@example.com'];

    postDelivery('postmark', $payload);
    postDelivery('postmark', $payload);

    expect(InboundWebhookEvent::count())->toBe(1);
});

it('normalises each provider vocabulary onto one set of types', function (string $raw, string $expected) {
    postDelivery('postmark', ['RecordType' => $raw, 'Email' => 'a@example.com', 'id' => 'x-'.$raw]);

    expect(InboundWebhookEvent::latest('id')->first()->event_type)->toBe($expected);
})->with([
    ['Bounce', InboundWebhookEvent::TYPE_BOUNCE],
    ['SpamComplaint', InboundWebhookEvent::TYPE_COMPLAINT],
    ['Delivery', InboundWebhookEvent::TYPE_DELIVERED],
    ['SoftBounce', InboundWebhookEvent::TYPE_SOFT_BOUNCE],
]);

it('reads the direction of a subscription change rather than assuming', function () {
    /*
     * Postmark sends `SubscriptionChange` for both directions and puts the
     * direction in `SuppressSending`. Treating the record type as an
     * unsubscribe would silently remove somebody who had just opted back in.
     */
    postDelivery('postmark', [
        'RecordType' => 'SubscriptionChange', 'SuppressSending' => false,
        'Email' => 'returning@example.com', 'id' => 'sc-1',
    ]);

    expect(InboundWebhookEvent::first()->event_type)->toBeNull();

    postDelivery('postmark', [
        'RecordType' => 'SubscriptionChange', 'SuppressSending' => true,
        'Email' => 'leaving@example.com', 'id' => 'sc-2',
    ]);

    expect(InboundWebhookEvent::latest('id')->first()->event_type)
        ->toBe(InboundWebhookEvent::TYPE_UNSUBSCRIBE);
});

it('acknowledges an event type it does not recognise without failing', function () {
    // Providers add event types without warning. Failing on them would fill the
    // queue with noise over something that is not a problem.
    postDelivery('postmark', ['RecordType' => 'SomethingNew', 'Email' => 'a@example.com', 'id' => 'e7']);

    $event = InboundWebhookEvent::first();
    app(DeliveryEventProcessor::class)->process($event);

    expect($event->fresh()->isProcessed())->toBeTrue();
});

// ── Acting on it ────────────────────────────────────────────────────────────

it('suppresses an address that hard-bounced', function () {
    // The whole point. Without this the list stays empty and the domain rots.
    $log = EmailLog::factory()->create(['to_address' => 'gone@example.com']);

    postDelivery('postmark', [
        'RecordType' => 'Bounce',
        'Email' => 'gone@example.com',
        'Details' => 'User unknown',
        'id' => 'b1',
    ]);

    app(DeliveryEventProcessor::class)->process(InboundWebhookEvent::first());

    expect(Suppression::blocks(Suppression::CHANNEL_EMAIL, 'gone@example.com', 'transactional'))
        ->toBeTrue()
        ->and($log->fresh()->status)->toBe(EmailLog::STATUS_BOUNCED)
        ->and($log->fresh()->error)->toBe('User unknown');
});

it('suppresses everything after a complaint', function () {
    EmailLog::factory()->create(['to_address' => 'annoyed@example.com']);

    postDelivery('postmark', [
        'RecordType' => 'SpamComplaint', 'Email' => 'annoyed@example.com', 'id' => 'c1',
    ]);

    app(DeliveryEventProcessor::class)->process(InboundWebhookEvent::first());

    expect(Suppression::firstWhere('address', 'annoyed@example.com')->scope)
        ->toBe(Suppression::SCOPE_ALL);
});

it('stops appeals but not receipts when somebody unsubscribes at their mailbox', function () {
    // The one-click List-Unsubscribe header honoured by the provider. They
    // asked to stop receiving appeals, not to stop receiving receipts.
    postDelivery('postmark', [
        'RecordType' => 'SubscriptionChange', 'SuppressSending' => true,
        'Email' => 'quiet@example.com', 'id' => 'u1',
    ]);

    app(DeliveryEventProcessor::class)->process(InboundWebhookEvent::first());

    expect(Suppression::blocks(Suppression::CHANNEL_EMAIL, 'quiet@example.com', 'marketing'))->toBeTrue()
        ->and(Suppression::blocks(Suppression::CHANNEL_EMAIL, 'quiet@example.com', 'transactional'))->toBeFalse();
});

it('does not suppress on a soft bounce alone', function () {
    // A full mailbox is not a dead address.
    EmailLog::factory()->create(['to_address' => 'full@example.com']);

    postDelivery('postmark', [
        'RecordType' => 'SoftBounce', 'Email' => 'full@example.com', 'id' => 's1',
    ]);

    app(DeliveryEventProcessor::class)->process(InboundWebhookEvent::first());

    expect(Suppression::count())->toBe(0)
        ->and(EmailLog::first()->status)->toBe(EmailLog::STATUS_SOFT_BOUNCED);
});

it('suppresses an address we have no log row for', function () {
    // A bounce for a message sent before logging existed, or from another
    // system on the same domain. Still a dead address.
    postDelivery('postmark', [
        'RecordType' => 'Bounce', 'Email' => 'stranger@example.com', 'id' => 'b2',
    ]);

    app(DeliveryEventProcessor::class)->process(InboundWebhookEvent::first());

    expect(Suppression::blocks(Suppression::CHANNEL_EMAIL, 'stranger@example.com', 'transactional'))
        ->toBeTrue();
});

it('marks an email delivered when the provider confirms it', function () {
    $log = EmailLog::factory()->create(['to_address' => 'donor@example.com']);

    postDelivery('postmark', ['RecordType' => 'Delivery', 'Email' => 'donor@example.com', 'id' => 'd1']);

    app(DeliveryEventProcessor::class)->process(InboundWebhookEvent::first());

    expect($log->fresh()->status)->toBe(EmailLog::STATUS_DELIVERED);
});

it('is safe to process the same event twice', function () {
    EmailLog::factory()->create(['to_address' => 'gone@example.com']);

    postDelivery('postmark', ['RecordType' => 'Bounce', 'Email' => 'gone@example.com', 'id' => 'b3']);

    $event = InboundWebhookEvent::first();
    $processor = app(DeliveryEventProcessor::class);

    $processor->process($event);
    $processor->process($event->fresh());

    expect(Suppression::count())->toBe(1);
});

// ── SMS ─────────────────────────────────────────────────────────────────────

it('honours a STOP reply immediately', function () {
    // In Ghana this is the only way many people will ever opt out of SMS, and
    // honouring it is both courtesy and what keeps a sender ID in good standing.
    config()->set('communications.webhooks.providers.postmark.channel', 'sms');

    postDelivery('postmark', ['status' => 'STOP', 'msisdn' => '0241234567', 'id' => 'sms1']);

    app(DeliveryEventProcessor::class)->process(InboundWebhookEvent::first());

    expect(Suppression::blocks(Suppression::CHANNEL_SMS, '+233241234567', 'marketing'))->toBeTrue();
});

it('records an SMS the network refused to deliver', function () {
    config()->set('communications.webhooks.providers.postmark.channel', 'sms');

    $log = SmsLog::factory()->create(['to_number' => '+233241234567']);

    postDelivery('postmark', ['status' => 'FAILED', 'msisdn' => '0241234567', 'id' => 'sms2']);

    app(DeliveryEventProcessor::class)->process(InboundWebhookEvent::first());

    expect($log->fresh()->status)->toBe(SmsLog::STATUS_UNDELIVERED);
});

// ── Resend, the provider actually chosen ────────────────────────────────────

const RESEND_SECRET = 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw'; // Svix docs' own example value

function postResend(array $payload, ?string $secret = RESEND_SECRET, ?int $timestamp = null, string $id = 'msg_1'): TestResponse
{
    config()->set('communications.webhooks.providers.resend.secret', RESEND_SECRET);

    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $timestamp ??= time();
    $headers = ['svix-id' => $id, 'svix-timestamp' => (string) $timestamp];

    if ($secret !== null) {
        $key = base64_decode(substr($secret, 6), true);
        $headers['svix-signature'] = 'v1,'.base64_encode(hash_hmac('sha256', $id.'.'.$timestamp.'.'.$body, $key, true));
    }

    return test()->call(
        'POST',
        '/webhooks/delivery/resend',
        [], [], [],
        collect($headers)->mapWithKeys(fn ($v, $k) => ['HTTP_'.strtoupper(str_replace('-', '_', $k)) => $v])->all()
        + ['CONTENT_TYPE' => 'application/json'],
        $body,
    );
}

it('verifies a Resend webhook the way Svix signs it', function () {
    postResend(['type' => 'email.delivered', 'data' => ['email_id' => 'r1', 'to' => ['donor@example.com']]])->assertOk();

    $event = InboundWebhookEvent::first();

    expect($event->signature_valid)->toBeTrue()
        ->and($event->event_type)->toBe(InboundWebhookEvent::TYPE_DELIVERED)
        ->and($event->subject_address)->toBe('donor@example.com')
        ->and($event->event_id)->toBe('resend:r1');
});

it('refuses a Resend signature made with another secret, or one that is too old to be live', function () {
    postResend(['type' => 'email.bounced', 'data' => ['email_id' => 'r2', 'to' => ['a@example.com']]], secret: 'whsec_'.base64_encode('wrong-key'));
    postResend(['type' => 'email.bounced', 'data' => ['email_id' => 'r3', 'to' => ['a@example.com']]], timestamp: time() - 3600, id: 'msg_2');

    expect(InboundWebhookEvent::where('signature_valid', true)->count())->toBe(0)
        ->and(Suppression::count())->toBe(0);
});

it('accepts any one of several rotating Resend signatures', function () {
    config()->set('communications.webhooks.providers.resend.secret', RESEND_SECRET);
    $body = json_encode(['type' => 'email.delivered', 'data' => ['email_id' => 'r4', 'to' => ['a@example.com']]]);
    $ts = time();
    $good = base64_encode(hash_hmac('sha256', 'msg_3.'.$ts.'.'.$body, base64_decode(substr(RESEND_SECRET, 6), true), true));

    test()->call('POST', '/webhooks/delivery/resend', [], [], [], [
        'HTTP_SVIX_ID' => 'msg_3', 'HTTP_SVIX_TIMESTAMP' => (string) $ts,
        'HTTP_SVIX_SIGNATURE' => 'v1,'.base64_encode('stale-key-signature').' v1,'.$good,
        'CONTENT_TYPE' => 'application/json',
    ], $body)->assertOk();

    expect(InboundWebhookEvent::first()->signature_valid)->toBeTrue();
});

it('suppresses on a permanent Resend bounce and only notes a transient one', function () {
    $processor = app(DeliveryEventProcessor::class);
    $dead = EmailLog::factory()->create(['to_address' => 'gone@example.com', 'status' => EmailLog::STATUS_SENT, 'sent_at' => now()]);
    $full = EmailLog::factory()->create(['to_address' => 'full@example.com', 'status' => EmailLog::STATUS_SENT, 'sent_at' => now()]);

    postResend(['type' => 'email.bounced', 'data' => ['email_id' => 'r5', 'to' => ['gone@example.com'], 'bounce' => ['type' => 'Permanent', 'message' => 'The recipient address does not exist.']]], id: 'msg_5');
    postResend(['type' => 'email.bounced', 'data' => ['email_id' => 'r6', 'to' => ['full@example.com'], 'bounce' => ['type' => 'Transient', 'message' => 'Mailbox full.']]], id: 'msg_6');
    postResend(['type' => 'email.delivery_delayed', 'data' => ['email_id' => 'r7', 'to' => ['full@example.com']]], id: 'msg_7');

    InboundWebhookEvent::all()->each(fn ($e) => $processor->process($e));

    expect(Suppression::blocks(Suppression::CHANNEL_EMAIL, 'gone@example.com', 'transactional'))->toBeTrue()
        ->and(Suppression::blocks(Suppression::CHANNEL_EMAIL, 'full@example.com', 'transactional'))->toBeFalse()
        ->and($dead->fresh()->status)->toBe(EmailLog::STATUS_BOUNCED)
        ->and($dead->fresh()->error)->toContain('does not exist')
        ->and(InboundWebhookEvent::where('event_id', 'resend:r7')->value('event_type'))->toBe(InboundWebhookEvent::TYPE_SOFT_BOUNCE);
});
