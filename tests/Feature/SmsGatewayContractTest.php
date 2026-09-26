<?php

declare(strict_types=1);

use App\Communications\ArkeselGateway;
use App\Communications\Contracts\ReportsBalance;
use App\Communications\Contracts\ReportsDelivery;
use App\Communications\Contracts\SmsGateway;
use App\Communications\HubtelGateway;
use App\Communications\LogSmsGateway;
use App\Communications\MnotifyGateway;
use App\Communications\SmsResult;
use App\Communications\TwilioGateway;
use App\Models\SmsLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 14 — the SMS driver contract, held by every driver
|--------------------------------------------------------------------------
|
| SmsGatewaysTest and MnotifyGatewayTest check each provider's happy path
| against that provider's own JSON. This file checks what the dispatcher
| relies on from ANY of them, in one place, so a sixth driver has a test to
| fail: a result and never an exception when the network is down or the
| provider answers with an HTML error page; the number and the text on the
| wire; a name the logs can tell apart; and the optional interfaces
| declared honestly.
|
*/

const SMS_DRIVERS = [
    'mnotify' => MnotifyGateway::class,
    'arkesel' => ArkeselGateway::class,
    'hubtel' => HubtelGateway::class,
    'twilio' => TwilioGateway::class,
];

beforeEach(function () {
    config([
        'communications.sms.arkesel.api_key' => 'ark-key',
        'communications.sms.hubtel.client_id' => 'hub-id',
        'communications.sms.hubtel.client_secret' => 'hub-secret',
        'communications.sms.twilio.account_sid' => 'ACxxx',
        'communications.sms.twilio.auth_token' => 'tok',
        'communications.sms.twilio.from' => '+15005550006',
        'communications.sms.mnotify.api_key' => 'mn-key',
    ]);
});

function contractLog(): SmsLog
{
    return SmsLog::factory()->create([
        'to_number' => '+233241234567',
        'sender_id' => 'GreaterHope',
        'body' => 'Your gift of GH₵ 50.00 was received. Thank you.',
    ]);
}

it('gives every driver a distinct name the logs can tell apart', function () {
    $names = array_map(fn (string $class): string => app($class)->name(), SMS_DRIVERS + ['log' => LogSmsGateway::class]);

    expect(array_unique($names))->toHaveCount(count($names))
        ->and(array_filter($names, fn (string $n): bool => trim($n) === ''))->toBe([]);

    foreach (SMS_DRIVERS + ['log' => LogSmsGateway::class] as $class) {
        expect(app($class))->toBeInstanceOf(SmsGateway::class);
    }
});

it('returns a rejection rather than throwing when the :dataset network is unreachable', function (string $class) {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Connection timed out'));

    $result = app($class)->send(contractLog());

    expect($result)->toBeInstanceOf(SmsResult::class)
        ->and($result->successful)->toBeFalse()
        ->and($result->providerMessageId)->toBeNull()
        ->and($result->message)->toContain('timed out');
})->with(SMS_DRIVERS);

it('returns a rejection rather than throwing when :dataset answers with an HTML error page', function (string $class) {
    Http::fake(['*' => Http::response('<html><body><h1>502 Bad Gateway</h1></body></html>', 502, ['Content-Type' => 'text/html'])]);

    $result = app($class)->send(contractLog());

    expect($result->successful)->toBeFalse()
        ->and($result->providerMessageId)->toBeNull()
        ->and($result->message)->not->toBe('');
})->with(SMS_DRIVERS);

it('treats a 200 with an unrecognised body from :dataset as not sent', function (string $class) {
    // "Nothing said no" is not "yes". A provider that changes its JSON must
    // show up as failures in the log, not as messages marked sent that
    // nobody received.
    Http::fake(['*' => Http::response(['unexpected' => 'shape'], 200)]);

    $result = app($class)->send(contractLog());

    expect($result->successful)->toBeFalse()->and($result->providerMessageId)->toBeNull();
})->with(SMS_DRIVERS);

it('puts the E.164 digits and the whole text on the wire for :dataset', function (string $class) {
    Http::fake(['*' => Http::response(['unexpected' => 'shape'], 200)]);

    app($class)->send(contractLog());

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request): bool {
        // JSON bodies escape the cedi sign as a  sequence; form bodies percent-encode
        // it. Decode whichever was sent so the text is compared as text.
        $wire = $request->isJson()
            ? json_encode(json_decode($request->body(), true), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : urldecode($request->body());

        return str_contains((string) $wire, '233241234567')
            && str_contains((string) $wire, 'Your gift of GH₵ 50.00 was received. Thank you.');
    });
})->with(SMS_DRIVERS);

it('never puts the credential in the URL for :dataset', function (string $class) {
    // Query strings end up in access logs and in the browser history of
    // whoever debugs from a browser. Headers and the body do not. mNotify is
    // the exception the test cannot hold: its API takes `key` as a query
    // parameter and nothing else, which is a reason to prefer Arkesel or
    // Hubtel, and is said in docs/PHASE-10-SMS-SENDER-ID.md.
    Http::fake(['*' => Http::response(['unexpected' => 'shape'], 200)]);

    app($class)->send(contractLog());

    Http::assertSent(function (Request $request): bool {
        $query = (string) parse_url($request->url(), PHP_URL_QUERY);

        foreach (['ark-key', 'hub-secret', 'tok'] as $secret) {
            if (str_contains($query, $secret)) {
                return false;
            }
        }

        return true;
    });
})->with(array_diff_key(SMS_DRIVERS, ['mnotify' => true]));

it('declares delivery reports and balance only where it can answer them', function (string $class) {
    $gateway = app($class);

    expect($gateway->supportsDeliveryReports())->toBe($gateway instanceof ReportsDelivery);

    if ($gateway instanceof ReportsBalance) {
        Http::fake(fn () => throw new ConnectionException('down'));

        // A balance the provider cannot answer is "unknown", never zero —
        // zero would page somebody at three in the morning about an outage.
        expect($gateway->balance())->toBeNull();
    }
})->with(SMS_DRIVERS);

it('sends nothing without credentials and says which key is missing, for :dataset', function (string $class, array $keys) {
    // The provider refuses to boot in production with a driver chosen and
    // no key (CommunicationServiceProvider). Outside production the send is
    // refused per message, with the .env key named, and nothing goes out.
    config(array_fill_keys($keys, ''));
    Http::fake();

    $result = app($class)->send(contractLog());

    expect($result->successful)->toBeFalse()
        ->and($result->message)->toContain('SMS_DRIVER=log');

    Http::assertNothingSent();
})->with([
    'mnotify' => [MnotifyGateway::class, ['communications.sms.mnotify.api_key']],
    'arkesel' => [ArkeselGateway::class, ['communications.sms.arkesel.api_key']],
    'hubtel' => [HubtelGateway::class, ['communications.sms.hubtel.client_id', 'communications.sms.hubtel.client_secret']],
    'twilio' => [TwilioGateway::class, ['communications.sms.twilio.account_sid', 'communications.sms.twilio.auth_token']],
]);

it('sends nothing at all through the log driver and still accepts the message', function () {
    Http::fake();

    $result = app(LogSmsGateway::class)->send(contractLog());

    expect($result->successful)->toBeTrue();
    Http::assertNothingSent();
});
