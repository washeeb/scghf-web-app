<?php

declare(strict_types=1);

use App\Chat\LiveChat;
use App\Chat\WhatsappChat;
use App\Chat\WhatsappInbox;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\SmsLog;
use App\Models\User;
use App\Models\WhatsappTemplate;
use App\Support\Settings;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| The live chat, over WhatsApp
|--------------------------------------------------------------------------
|
| A message to the foundation's number becomes a conversation in the same
| inbox, answered by the same assistant under the same rules, and a reply
| typed by the office goes back to the phone.
|
| The rule this file mostly exists to hold in place is Meta's 24-hour
| window: past it, a free-form reply is refused by Meta, and the failure
| mode without this code is a member of staff's answer silently not
| arriving.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);

    config([
        'features.live_chat' => true,
        'features.whatsapp' => true,
        'features.chat_agent' => false,
        'communications.whatsapp.access_token' => 'test-token',
        'communications.whatsapp.phone_number_id' => '123456',
        'communications.whatsapp.base_url' => 'https://graph.example.test',
    ]);

    app(Settings::class)->set('chat.whatsapp_enabled', true);
    app(Settings::class)->set('chat.notify_email', 'office@example.test');
    app(Settings::class)->flush();

    Http::fake(['graph.example.test/*' => Http::response(['messages' => [['id' => 'wamid.test']]])]);
});

/** One inbound text message, as Meta delivers it. */
function inbound(string $body, string $from = '233277326833', string $name = 'Ama'): array
{
    return ['entry' => [['changes' => [['value' => [
        'messaging_product' => 'whatsapp',
        'contacts' => [['wa_id' => $from, 'profile' => ['name' => $name]]],
        'messages' => [[
            'from' => $from,
            'id' => 'wamid.in.'.bin2hex(random_bytes(4)),
            'type' => 'text',
            'text' => ['body' => $body],
        ]],
    ]]]]]];
}

it('turns a message to the foundation\'s number into a chat in the same inbox', function () {
    app(WhatsappInbox::class)->receive(inbound('Do you have a shop?'));

    $conversation = ChatConversation::query()->firstOrFail();

    expect($conversation->channel)->toBe(ChatConversation::CHANNEL_WHATSAPP)
        ->and($conversation->whatsapp_wa_id)->toBe('233277326833')
        ->and($conversation->visitor_name)->toBe('Ama')
        ->and($conversation->whatsappWindowOpen())->toBeTrue()
        ->and($conversation->messages()->where('sender', ChatMessage::SENDER_VISITOR)->value('body'))->toBe('Do you have a shop?');

    // Nothing is sent back for the visitor's own message.
    Http::assertNothingSent();
});

it('keeps one conversation per number while it is open, and starts a new one after it closes', function () {
    app(WhatsappInbox::class)->receive(inbound('First question'));
    app(WhatsappInbox::class)->receive(inbound('Second question'));

    expect(ChatConversation::query()->count())->toBe(1)
        ->and(ChatConversation::query()->firstOrFail()->messages()->where('sender', ChatMessage::SENDER_VISITOR)->count())->toBe(2);

    ChatConversation::query()->firstOrFail()->forceFill(['status' => ChatConversation::STATUS_CLOSED])->save();

    app(WhatsappInbox::class)->receive(inbound('A new question next week'));

    expect(ChatConversation::query()->count())->toBe(2);
});

it('sends a reply from the office to the phone while Meta\'s window is open', function () {
    app(WhatsappInbox::class)->receive(inbound('Where are you?'));

    $conversation = ChatConversation::query()->firstOrFail();
    $staff = User::factory()->staff()->withTwoFactor()->create();
    $staff->assignRole('Support');

    app(LiveChat::class)->staffReply($conversation, $staff, 'We are on Adam Lane in Kasoa.');

    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), '/123456/messages')
        && ($request->data()['type'] ?? null) === 'text'
        && str_contains((string) data_get($request->data(), 'text.body'), 'Adam Lane')
        // Who is speaking: on WhatsApp every message looks the same.
        && str_starts_with((string) data_get($request->data(), 'text.body'), (string) str($staff->name)->before(' ')));

    $log = SmsLog::query()->where('channel', SmsLog::CHANNEL_WHATSAPP)->latest('id')->firstOrFail();

    expect($log->status)->toBe(SmsLog::STATUS_SENT)
        ->and($log->provider_message_id)->toBe('wamid.test')
        ->and($log->related_id)->toBe($conversation->getKey());
});

it('uses the approved template once the 24-hour window has closed', function () {
    app(WhatsappInbox::class)->receive(inbound('A question last night'));

    $conversation = ChatConversation::query()->firstOrFail();
    $conversation->forceFill(['whatsapp_window_expires_at' => now()->subHour()])->save();

    WhatsappTemplate::query()->where('key', 'chat.reply')->update(['is_active' => true, 'meta_name' => 'scghf_chat_reply']);

    $staff = User::factory()->staff()->withTwoFactor()->create();
    $staff->assignRole('Support');

    app(LiveChat::class)->staffReply($conversation->refresh(), $staff, 'Sorry for the delay — yes we do.');

    Http::assertSent(fn ($request): bool => ($request->data()['type'] ?? null) === 'template'
        && data_get($request->data(), 'template.name') === 'scghf_chat_reply');

    expect(SmsLog::query()->latest('id')->value('status'))->toBe(SmsLog::STATUS_SENT);
});

it('records why a reply did not arrive rather than dropping it', function () {
    app(WhatsappInbox::class)->receive(inbound('A question last night'));

    $conversation = ChatConversation::query()->firstOrFail();
    $conversation->forceFill(['whatsapp_window_expires_at' => now()->subHour()])->save();

    // No approved template: Meta would refuse this, so it must not look sent.
    WhatsappTemplate::query()->where('key', 'chat.reply')->update(['is_active' => false]);

    $staff = User::factory()->staff()->withTwoFactor()->create();
    $staff->assignRole('Support');

    app(LiveChat::class)->staffReply($conversation->refresh(), $staff, 'Sorry for the delay.');

    $log = SmsLog::query()->latest('id')->firstOrFail();

    expect($log->status)->toBe(SmsLog::STATUS_FAILED)
        ->and($log->error)->toContain('24-hour window');
});

it('tells the office when somebody sends something the assistant cannot read', function () {
    $payload = inbound('ignored');
    data_set($payload, 'entry.0.changes.0.value.messages.0.type', 'image');
    data_set($payload, 'entry.0.changes.0.value.messages.0.text', null);

    app(WhatsappInbox::class)->receive($payload);

    expect(ChatConversation::query()->firstOrFail()->messages()->where('sender', ChatMessage::SENDER_VISITOR)->value('body'))
        ->toContain('Open WhatsApp to see it');
});

it('does nothing at all when the channel is switched off', function () {
    app(Settings::class)->set('chat.whatsapp_enabled', false);
    app(Settings::class)->flush();

    app(WhatsappInbox::class)->receive(inbound('Hello?'));

    expect(ChatConversation::query()->count())->toBe(0)
        ->and(app(WhatsappChat::class)->enabled())->toBeFalse();
});

it('arrives through the signed webhook Meta already uses for delivery reports', function () {
    config()->set('communications.webhooks.providers.meta', [
        'channel' => 'whatsapp',
        'secret' => 'meta-secret',
        'signature_header' => 'x-hub-signature-256',
        'prefix' => 'sha256=',
        'algorithm' => 'sha256',
    ]);

    $body = json_encode(inbound('Through the front door'), JSON_THROW_ON_ERROR);

    $this->call(
        'POST',
        '/webhooks/delivery/meta',
        [], [], [],
        [
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'meta-secret'),
            'CONTENT_TYPE' => 'application/json',
        ],
        $body,
    )->assertOk();

    expect(ChatConversation::query()->where('channel', ChatConversation::CHANNEL_WHATSAPP)->count())->toBe(1);
});
