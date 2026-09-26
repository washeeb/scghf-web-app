<?php

declare(strict_types=1);

use App\Chat\LiveChat;
use App\Filament\Resources\ChatConversations\Pages\ViewChatConversation;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ScheduledMessage;
use App\Models\User;
use App\Support\RetentionRunner;
use App\Support\Settings;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Live chat
|--------------------------------------------------------------------------
|
| The visitor's four endpoints, the token that guards them, the office's
| screen, presence, the words from the settings, the switches, and the
| retention run that ends a conversation's life.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    app(Settings::class)->set('chat.notify_email', 'office@example.test');
    app(Settings::class)->flush();
    config(['features.live_chat' => true]);
});

/** A member of staff who can pass the admin's two-factor gate. */
function chatStaff(string $role): User
{
    $user = User::factory()->staff()->withTwoFactor()->create();
    $user->assignRole($role);

    return $user->fresh();
}

/** @return array{id: string, token: string} */
function startChat(string $message = 'Hello, can I give by MoMo?'): array
{
    $response = test()->postJson(route('chat.start'), [
        'name' => 'Ama Test',
        'email' => 'ama@example.test',
        'message' => $message,
        'page' => 'https://example.test/donate',
    ])->assertCreated();

    return ['id' => $response->json('id'), 'token' => $response->json('token')];
}

// ── The visitor ──────────────────────────────────────────────────────────────

it('starts a chat, stores the token hashed, and emails the office once', function () {
    $chat = startChat();

    $conversation = ChatConversation::query()->where('ulid', $chat['id'])->firstOrFail();

    expect($conversation->visitor_name)->toBe('Ama Test')
        ->and($conversation->visitor_email)->toBe('ama@example.test')
        ->and($conversation->visitor_token_hash)->toBe(hash('sha256', $chat['token']))
        ->and($conversation->visitor_token_hash)->not->toBe($chat['token'])
        ->and($conversation->messages()->count())->toBe(1)
        ->and($conversation->isOpen())->toBeTrue();

    expect(ScheduledMessage::query()->where('template_key', 'chat.new_conversation')->count())->toBe(1);
});

it('refuses to serve a conversation without its token, and answers 404 rather than 403', function () {
    $chat = startChat();

    $this->getJson(route('chat.messages', $chat['id']))->assertNotFound();
    $this->withHeader('X-Chat-Token', 'wrong')->getJson(route('chat.messages', $chat['id']))->assertNotFound();
    $this->withHeader('X-Chat-Token', $chat['token'])->getJson(route('chat.messages', $chat['id']))->assertOk()
        ->assertJsonPath('status', 'open')
        ->assertJsonCount(1, 'messages');
});

it('lets the visitor write and read, and only new lines after the last one seen', function () {
    $chat = startChat();
    $headers = ['X-Chat-Token' => $chat['token']];

    $this->withHeaders($headers)->postJson(route('chat.send', $chat['id']), ['body' => 'Second line'])->assertCreated();

    $all = $this->withHeaders($headers)->getJson(route('chat.messages', $chat['id']))->assertOk()->json('messages');
    expect($all)->toHaveCount(2);

    $this->withHeaders($headers)->getJson(route('chat.messages', ['conversation' => $chat['id'], 'after' => $all[0]['id']]))
        ->assertOk()->assertJsonCount(1, 'messages')->assertJsonPath('messages.0.body', 'Second line');
});

it('requires an email from a guest when the setting says so, and not when it does not', function () {
    $this->postJson(route('chat.start'), ['name' => 'Kofi', 'message' => 'Hi'])->assertUnprocessable()->assertJsonValidationErrors('email');

    app(Settings::class)->set('chat.require_email', false);
    app(Settings::class)->flush();

    $this->postJson(route('chat.start'), ['name' => 'Kofi', 'message' => 'Hi'])->assertCreated();
});

it('renders nothing and answers 404 when the chat is switched off in the settings or by the flag', function () {
    $this->get('/')->assertOk()->assertSee('data-chat-launcher', false);

    app(Settings::class)->set('chat.enabled', false);
    app(Settings::class)->flush();
    $this->get('/')->assertOk()->assertDontSee('data-chat-launcher', false);
    $this->postJson(route('chat.start'), ['name' => 'Ama', 'email' => 'a@b.test', 'message' => 'Hi'])->assertNotFound();

    app(Settings::class)->set('chat.enabled', true);
    app(Settings::class)->flush();
    config(['features.live_chat' => false]);
    $this->get('/')->assertOk()->assertDontSee('data-chat-launcher', false);
});

it('puts every word of the widget in the settings', function () {
    $s = app(Settings::class);
    $s->set('chat.title', 'Talk to Ama');
    $s->set('chat.button_label', 'Ask us');
    $s->set('chat.greeting', 'Akwaaba!');
    $s->set('chat.away_label', 'Back soon');
    $s->set('chat.position', 'left');
    $s->flush();

    $this->get('/')->assertOk()
        ->assertSee('Talk to Ama')->assertSee('Ask us')->assertSee('Akwaaba!')->assertSee('Back soon')
        ->assertSee('left-4 sm:left-6', false);
});

it('says the office is online only while somebody has the inbox open', function () {
    $chat = startChat();
    $headers = ['X-Chat-Token' => $chat['token']];

    expect($this->withHeaders($headers)->getJson(route('chat.messages', $chat['id']))->json('online'))->toBeFalse();

    app(LiveChat::class)->touchPresence(staffWithRole('Support'));

    expect($this->withHeaders($headers)->getJson(route('chat.messages', $chat['id']))->json('online'))->toBeTrue()
        ->and($this->getJson(route('chat.status'))->json('online'))->toBeTrue();

    $this->travel(LiveChat::PRESENCE_MINUTES + 1)->minutes();
    expect($this->getJson(route('chat.status'))->json('online'))->toBeFalse();
});

// ── The office ───────────────────────────────────────────────────────────────

it('lets Support answer from the admin screen, assigns them, and the visitor reads the answer', function () {
    $chat = startChat();
    $conversation = ChatConversation::query()->where('ulid', $chat['id'])->firstOrFail();
    $support = staffWithRole('Support');

    Livewire::actingAs($support)
        ->test(ViewChatConversation::class, ['record' => $conversation->ulid])
        ->set('reply', 'Yes — MoMo works on the donate page.')
        ->call('send')
        ->assertSet('reply', '');

    $conversation->refresh();
    expect($conversation->assigned_to)->toBe($support->getKey())
        ->and($conversation->hasUnreadForStaff())->toBeFalse();

    $messages = $this->withHeader('X-Chat-Token', $chat['token'])->getJson(route('chat.messages', $chat['id']))->json('messages');
    expect($messages)->toHaveCount(2)
        ->and($messages[1]['sender'])->toBe('staff')
        ->and($messages[1]['name'])->toBe(str($support->name)->before(' ')->toString());
});

it('keeps somebody without chat.view out of the inbox', function () {
    $chat = startChat();
    $conversation = ChatConversation::query()->where('ulid', $chat['id'])->firstOrFail();

    $this->actingAs(chatStaff('Content Editor'))
        ->get(route('filament.admin.resources.chat-conversations.view', $conversation))
        ->assertForbidden();

    $this->actingAs(chatStaff('Support'))
        ->get(route('filament.admin.resources.chat-conversations.view', $conversation))
        ->assertOk()->assertSee('Ama Test');
});

it('closes from either side with a closing line, refuses further messages, and emails the transcript', function () {
    $chat = startChat();
    $headers = ['X-Chat-Token' => $chat['token']];
    $conversation = ChatConversation::query()->where('ulid', $chat['id'])->firstOrFail();

    app(LiveChat::class)->close($conversation, staffWithRole('Support'));

    expect($conversation->refresh()->isOpen())->toBeFalse()
        ->and($conversation->messages()->where('sender', ChatMessage::SENDER_SYSTEM)->exists())->toBeTrue()
        ->and(ScheduledMessage::query()->where('template_key', 'chat.transcript')->where('to_address', 'ama@example.test')->exists())->toBeTrue();

    $this->withHeaders($headers)->postJson(route('chat.send', $chat['id']), ['body' => 'Still there?'])->assertStatus(409);

    // And from the visitor's side.
    $second = startChat('Another question');
    $this->withHeader('X-Chat-Token', $second['token'])->postJson(route('chat.close', $second['id']))->assertOk()->assertJsonPath('status', 'closed');
});

// ── Retention ────────────────────────────────────────────────────────────────

it('deletes a conversation and its messages twelve months after the last line', function () {
    $chat = startChat();
    $conversation = ChatConversation::query()->where('ulid', $chat['id'])->firstOrFail();

    $conversation->forceFill(['last_message_at' => now()->subMonths(14)])->save();

    $summary = app(RetentionRunner::class)->run(execute: true, actor: User::factory()->create());

    expect($summary['acted'])->toBeGreaterThanOrEqual(1)
        ->and(ChatConversation::query()->whereKey($conversation->getKey())->exists())->toBeFalse()
        ->and(ChatMessage::query()->where('chat_conversation_id', $conversation->getKey())->count())->toBe(0);
});
