<?php

declare(strict_types=1);

use App\Communications\Contracts\SmsGateway;
use App\Communications\MessageDispatcher;
use App\Communications\MnotifyGateway;
use App\Models\SmsLog;
use App\Models\SmsTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| mNotify
|--------------------------------------------------------------------------
|
| The provider the Foundation chose, sending under the registered sender ID
| "GreaterHope".
|
| The failure these tests are really about is the invisible one. An
| alphanumeric sender ID that is not registered with MTN, Telecel and AT is
| ACCEPTED by mNotify and DROPPED by the network, with no error returned
| anywhere. From our side a blocked sender and a working one look identical.
|
| So: accepting is never read as delivering, an unrecognised response is a
| failure rather than a shrug, and the errors that break every message —
| no credit, bad key, rejected sender — are told apart from the ones that
| break only this one.
|
*/

beforeEach(function () {
    config()->set('communications.sms.driver', 'mnotify');
    config()->set('communications.sms.mnotify.api_key', 'test-key');
    config()->set('communications.sms.sender_id', 'GreaterHope');

    // Rebind so the container hands out the real gateway rather than `log`.
    app()->forgetInstance(SmsGateway::class);
    app()->forgetInstance(MessageDispatcher::class);
});

function mnotifyAccepted(array $overrides = []): array
{
    return array_replace_recursive([
        'status' => 'success',
        'code' => '2000',
        'message' => 'messages sent successfully',
        'summary' => [
            '_id' => 'campaign-123',
            'total_sent' => 1,
            'total_rejected' => 0,
            'credit_used' => 1,
            'credit_left' => 500,
        ],
    ], $overrides);
}

function sendOneSms(): SmsLog
{
    $template = SmsTemplate::factory()->create([
        'body' => 'Thank you for your gift of GHS {{amount}}.',
        'required_variables' => ['amount'],
    ]);

    return app(MessageDispatcher::class)
        ->sendSmsNow($template->key, '0241234567', ['amount' => '50.00']);
}

// ── Sending ─────────────────────────────────────────────────────────────────

it('sends under the registered sender ID, with the number in the format mNotify wants', function () {
    Http::fake(['*' => Http::response(mnotifyAccepted())]);

    sendOneSms();

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/sms/quick')
            && str_contains($request->url(), 'key=test-key')
            // E.164 everywhere in our own tables; no leading plus on the wire.
            && $request['recipient'] === ['233241234567']
            && $request['sender'] === 'GreaterHope';
    });
});

it('records the campaign id, which is the only handle for asking later', function () {
    Http::fake(['*' => Http::response(mnotifyAccepted())]);

    $log = sendOneSms();

    expect($log->status)->toBe(SmsLog::STATUS_SENT)
        ->and($log->provider_message_id)->toBe('campaign-123')
        ->and($log->provider_status)->toBe('2000');
});

it('does not read acceptance as delivery', function () {
    // The whole point. mNotify accepting a message says nothing about whether
    // MTN delivered it — and an unregistered sender fails exactly here.
    Http::fake(['*' => Http::response(mnotifyAccepted())]);

    expect(sendOneSms()->delivered_at)->toBeNull();
});

it('keeps the message body out of the stored payload', function () {
    // It is already in its own column. A second copy in a JSON blob is one more
    // place a prayer request has to be found and destroyed at retention time.
    Http::fake(['*' => Http::response(mnotifyAccepted(['summary' => ['numbers_sent' => ['233241234567']]]))]);

    $gateway = app(MnotifyGateway::class);
    $log = sendOneSms();
    $result = $gateway->send($log);

    expect($result->raw)->not->toHaveKey('message')
        ->and($result->raw['summary'] ?? [])->not->toHaveKey('numbers_sent');
});

// ── Failures ────────────────────────────────────────────────────────────────

it('names the sender ID when mNotify rejects it', function () {
    // Code 1006. This one breaks every message, not just this one, so the
    // explanation has to say what to do about it.
    Http::fake(['*' => Http::response(['status' => 'error', 'code' => '1006'])]);

    $log = sendOneSms();

    expect($log->status)->toBe(SmsLog::STATUS_FAILED)
        ->and($log->error)->toContain('sender ID')
        ->and($log->error)->toContain('registered');
});

it('says plainly when the account has run out of credit', function () {
    Http::fake(['*' => Http::response(['status' => 'error', 'code' => '1003'])]);

    expect(sendOneSms()->error)->toContain('topping up');
});

it('treats an unrecognised response as a failure, not as a success', function () {
    // "Probably fine" is how a provider changing its API goes unnoticed until
    // somebody asks why nobody got their receipt.
    Http::fake(['*' => Http::response(['status' => 'ok', 'code' => '9999'])]);

    $log = sendOneSms();

    expect($log->status)->toBe(SmsLog::STATUS_FAILED)
        ->and($log->error)->toContain('unrecognised');
});

it('records a message it could not send at all', function () {
    Http::fake(['*' => Http::response('gateway timeout', 504)]);

    expect(sendOneSms()->status)->toBe(SmsLog::STATUS_FAILED);
});

it('refuses to send with no API key rather than pretending', function () {
    config()->set('communications.sms.mnotify.api_key', '');

    $log = sendOneSms();

    expect($log->status)->toBe(SmsLog::STATUS_FAILED)
        ->and($log->error)->toContain('MNOTIFY_API_KEY');
});

// ── Delivery reports ────────────────────────────────────────────────────────

it('marks a message delivered only when the network says so', function () {
    Http::fake([
        '*/sms/quick*' => Http::response(mnotifyAccepted()),
        '*/status/*' => Http::response([
            'status' => 'success',
            'code' => '2000',
            'report' => [['recipient' => '233241234567', 'status' => 'DELIVERED']],
        ]),
    ]);

    $log = sendOneSms();

    $this->artisan('scghf:sms-delivery-reports')->assertSuccessful();

    expect($log->fresh()->status)->toBe(SmsLog::STATUS_DELIVERED)
        ->and($log->fresh()->delivered_at)->not->toBeNull();
});

it('records a message the network refused to deliver', function () {
    Http::fake([
        '*/sms/quick*' => Http::response(mnotifyAccepted()),
        '*/status/*' => Http::response([
            'report' => [['recipient' => '233241234567', 'status' => 'FAILED']],
        ]),
    ]);

    $log = sendOneSms();

    $this->artisan('scghf:sms-delivery-reports');

    expect($log->fresh()->status)->toBe(SmsLog::STATUS_UNDELIVERED);
});

it('leaves a message alone while there is still no report', function () {
    // "Do not know" is the truthful answer, and `sent` is how it is recorded.
    Http::fake([
        '*/sms/quick*' => Http::response(mnotifyAccepted()),
        '*/status/*' => Http::response(['report' => [['status' => 'PENDING']]]),
    ]);

    $log = sendOneSms();

    $this->artisan('scghf:sms-delivery-reports');

    expect($log->fresh()->status)->toBe(SmsLog::STATUS_SENT);
});

it('raises an alert when delivery collapses, which is what a dead sender ID looks like', function () {
    // Twenty reported messages, none delivered. From our side sending looks
    // perfect — this is the only evidence anything is wrong.
    SmsLog::factory()->count(20)->create([
        'status' => SmsLog::STATUS_UNDELIVERED,
        'sent_at' => now()->subHour(),
    ]);

    Http::fake(['*' => Http::response(['report' => []])]);

    $this->artisan('scghf:sms-delivery-reports')
        ->expectsOutputToContain('GreaterHope')
        ->assertFailed();
});

it('does not cry wolf on a handful of messages', function () {
    SmsLog::factory()->count(3)->create([
        'status' => SmsLog::STATUS_UNDELIVERED,
        'sent_at' => now()->subHour(),
    ]);

    Http::fake(['*' => Http::response(['report' => []])]);

    $this->artisan('scghf:sms-delivery-reports')->assertSuccessful();
});

it('does nothing at all on the log driver, which cannot report delivery', function () {
    config()->set('communications.sms.driver', 'log');
    app()->forgetInstance(SmsGateway::class);

    $this->artisan('scghf:sms-delivery-reports')
        ->expectsOutputToContain('cannot report delivery')
        ->assertSuccessful();
});
