<?php

declare(strict_types=1);

use App\Ai\AnthropicChatModel;
use App\Chat\Agent\ChatAgent;
use App\Chat\Agent\KnowledgeBase;
use App\Chat\LiveChat;
use App\Filament\Pages\AssistantPage;
use App\Filament\Resources\ChatConversations\Pages\ViewChatConversation;
use App\Models\AiInteraction;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ContactDepartment;
use App\Models\Faq;
use App\Models\ScheduledMessage;
use App\Models\User;
use App\Support\LaunchChecks;
use App\Support\Settings;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| The chat assistant
|--------------------------------------------------------------------------
|
| What it answers, and — the part that matters for a foundation — everything
| it must refuse to answer: a disclosure of harm, a question about somebody's
| own donation, a visitor who simply wants a person. Those are decided in PHP
| before a model is called, so they are tested without one.
|
| The model itself is faked at the HTTP boundary rather than by swapping the
| driver, so the request shape, the token accounting and the hand-over marker
| are all under test too.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);

    config([
        'features.live_chat' => true,
        'features.chat_agent' => true,
        'ai.driver' => 'anthropic',
        'ai.anthropic.api_key' => 'test-key',
        'ai.anthropic.model' => 'claude-sonnet-5',
        'ai.monthly_budget_minor' => 20_000,
    ]);

    app(Settings::class)->set('agent.enabled', true);
    app(Settings::class)->set('chat.notify_email', 'office@example.test');
    app(Settings::class)->flush();

    app(KnowledgeBase::class)->forget();
});

/** The model answers with this text. */
function modelSays(string $text, int $inputTokens = 1_200, int $outputTokens = 60): void
{
    Http::fake(['api.anthropic.com/*' => Http::response([
        'id' => 'msg_test',
        'content' => [['type' => 'text', 'text' => $text]],
        'stop_reason' => 'end_turn',
        'usage' => ['input_tokens' => $inputTokens, 'output_tokens' => $outputTokens],
    ])]);
}

/** @return array{id: string, token: string} */
function startAgentChat(string $message): array
{
    $response = test()->postJson(route('chat.start'), [
        'name' => 'Ama Test',
        'email' => 'ama@example.test',
        'message' => $message,
        'page' => 'https://example.test/donate',
    ])->assertCreated();

    return ['id' => $response->json('id'), 'token' => $response->json('token')];
}

function agentConversation(): ChatConversation
{
    return ChatConversation::query()->latest('id')->firstOrFail();
}

// ── Answering ────────────────────────────────────────────────────────────────

it('answers the first question, says plainly that it is a machine, and records what it cost', function () {
    modelSays('We take mobile money on the giving page. Every gift is in cedis.');

    $chat = startAgentChat('Can I give by MoMo?');
    $conversation = agentConversation();

    expect($conversation->handled_by)->toBe(ChatConversation::HANDLER_AGENT)
        ->and($conversation->agent_replies)->toBe(1);

    $messages = $conversation->messages()->get();

    // The disclosure comes BEFORE the visitor's own first line.
    expect($messages->first()->sender)->toBe(ChatMessage::SENDER_SYSTEM)
        ->and($messages->first()->body)->toContain('automated assistant')
        ->and($messages->last()->sender)->toBe(ChatMessage::SENDER_AGENT)
        ->and($messages->last()->body)->toContain('mobile money');

    $interaction = AiInteraction::query()->latest('id')->firstOrFail();

    expect($interaction->outcome)->toBe(AiInteraction::OUTCOME_ANSWERED)
        ->and($interaction->input_tokens)->toBe(1_200)
        ->and($interaction->estimated_cost_minor)->toBeGreaterThan(0)
        ->and($messages->last()->ai_interaction_id)->toBe($interaction->getKey());

    // And the widget is told who is answering, so it can offer a person.
    test()->withHeaders(['X-Chat-Token' => $chat['token']])
        ->getJson(route('chat.messages', $chat['id']))
        ->assertOk()
        ->assertJsonPath('agent.active', true);
});

it('sends the foundation\'s own published words, and nothing else, as the source', function () {
    Faq::create(['question' => 'Do you take mobile money?', 'answer' => 'Yes, MTN and Telecel.', 'is_published' => true]);
    Faq::create(['question' => 'A draft nobody has published', 'answer' => 'Secret working note.', 'is_published' => false]);
    app(KnowledgeBase::class)->forget();

    modelSays('Yes, we do.');
    startAgentChat('Do you take mobile money?');

    Http::assertSent(function ($request): bool {
        $system = (string) ($request->data()['system'] ?? '');

        return str_contains($system, 'MTN and Telecel')
            && ! str_contains($system, 'Secret working note')
            // The safety rules are appended last and cannot be edited away.
            && str_contains($system, 'WHEN YOU MUST HAND OVER TO A PERSON')
            && str_contains($system, 'Ghana Cedis');
    });
});

// ── Refusing ─────────────────────────────────────────────────────────────────

it('never lets a machine answer a disclosure of harm, and shows the crisis line first', function () {
    Http::fake();

    startAgentChat('My neighbour is beating her child and I do not know what to do');

    $conversation = agentConversation();
    $bodies = $conversation->messages()->pluck('body')->implode(' | ');

    expect($conversation->handled_by)->toBe(ChatConversation::HANDLER_STAFF)
        ->and($conversation->escalation_reason)->toBe('topic:safeguarding')
        ->and($conversation->department?->key)->toBe('safeguarding')
        ->and($conversation->messages()->where('sender', ChatMessage::SENDER_AGENT)->exists())->toBeFalse()
        ->and($bodies)->toContain('passed this to the team');

    // No model was called at all: the rule fires before the request.
    Http::assertNothingSent();

    expect(AiInteraction::query()->where('outcome', AiInteraction::OUTCOME_HANDOVER)->exists())->toBeTrue();
});

it('shows the emergency line and fetches a person when somebody is in danger', function () {
    Http::fake();

    startAgentChat('i want to kill myself');

    $conversation = agentConversation();

    expect($conversation->escalation_reason)->toBe('crisis')
        ->and($conversation->messages()->pluck('body')->implode(' '))->toContain('191');

    Http::assertNothingSent();
});

it('hands over the moment a visitor asks for a person, and routes a money question to the right department', function () {
    Http::fake();

    startAgentChat('can i speak to someone please');
    expect(agentConversation()->escalation_reason)->toBe('visitor_asked');

    ChatConversation::query()->delete();

    // The seeded address is a {{PLACEHOLDER}} until somebody fills it in, and
    // nothing is sent to one of those — so give this department a real one.
    ContactDepartment::query()->where('key', 'donations')->update(['email' => 'giving@example.test']);

    startAgentChat('I was charged twice for my donation last night');
    $conversation = agentConversation();

    expect($conversation->escalation_reason)->toBe('topic:donations')
        ->and($conversation->department?->key)->toBe('donations')
        // The department's own address, not the general chat one.
        ->and(ScheduledMessage::query()
            ->where('template_key', 'chat.handover')
            ->where('to_address', 'giving@example.test')
            ->exists())->toBeTrue();

    Http::assertNothingSent();
});

it('stops answering after the ceiling in the settings, however well it is going', function () {
    app(Settings::class)->set('agent.max_replies', 2);
    app(Settings::class)->flush();

    modelSays('Here is an answer.');

    $chat = startAgentChat('What do you do?');

    test()->withHeaders(['X-Chat-Token' => $chat['token']])
        ->postJson(route('chat.send', $chat['id']), ['body' => 'And where are you based?'])
        ->assertCreated();

    expect(agentConversation()->agent_replies)->toBe(2);

    test()->withHeaders(['X-Chat-Token' => $chat['token']])
        ->postJson(route('chat.send', $chat['id']), ['body' => 'One more thing'])
        ->assertCreated()
        ->assertJsonPath('agent.active', false);

    expect(agentConversation()->escalation_reason)->toBe('reply_limit');
});

it('answers and then hands over when the model asks for a person itself', function () {
    modelSays("I am not able to check a particular order, so let me fetch somebody.\n[[HANDOVER: shop | asking about a specific order]]");

    startAgentChat('A question about something I bought');

    $conversation = agentConversation();
    $last = $conversation->messages()->where('sender', ChatMessage::SENDER_AGENT)->latest('id')->first();

    // The marker never reaches the visitor.
    expect($last->body)->not->toContain('HANDOVER')
        ->and($last->body)->toContain('fetch somebody')
        ->and($conversation->handled_by)->toBe(ChatConversation::HANDLER_STAFF)
        ->and($conversation->escalation_reason)->toContain('specific order')
        ->and($conversation->department?->key)->toBe('shop');
});

// ── When it cannot answer ────────────────────────────────────────────────────

it('gives the chat to a person when the provider fails, and records why', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['type' => 'overloaded_error']], 529)]);

    startAgentChat('What do you do?');

    $conversation = agentConversation();

    expect($conversation->handled_by)->toBe(ChatConversation::HANDLER_STAFF)
        ->and($conversation->escalation_reason)->toBe('unavailable')
        ->and(AiInteraction::query()->where('outcome', AiInteraction::OUTCOME_FAILED)->value('reason'))->toContain('529');
});

it('stops spending once the month\'s ceiling is reached', function () {
    AiInteraction::create([
        'driver' => 'anthropic', 'outcome' => AiInteraction::OUTCOME_ANSWERED,
        'estimated_cost_minor' => 20_000,
    ]);

    Http::fake();

    startAgentChat('What do you do?');

    expect(agentConversation()->handled_by)->toBe(ChatConversation::HANDLER_STAFF);
    Http::assertNothingSent();
});

it('never answers with no driver configured, whatever the settings say', function () {
    config(['ai.driver' => 'null']);
    Http::fake();

    startAgentChat('What do you do?');

    expect(agentConversation()->handled_by)->toBe(ChatConversation::HANDLER_STAFF)
        ->and(agentConversation()->messages()->where('sender', ChatMessage::SENDER_AGENT)->exists())->toBeFalse();

    Http::assertNothingSent();
});

it('leaves every chat to a person while somebody has the inbox open, unless told otherwise', function () {
    modelSays('An answer.');

    $staff = User::factory()->staff()->withTwoFactor()->create();
    $staff->assignRole('Support');
    app(LiveChat::class)->touchPresence($staff);

    startAgentChat('What do you do?');
    expect(agentConversation()->handled_by)->toBe(ChatConversation::HANDLER_STAFF);

    app(Settings::class)->set('agent.when_staff_online', true);
    app(Settings::class)->flush();
    ChatConversation::query()->delete();

    startAgentChat('What do you do?');
    expect(agentConversation()->handled_by)->toBe(ChatConversation::HANDLER_AGENT);
});

// ── A person takes over ──────────────────────────────────────────────────────

it('gives the visitor a person on request, and will not hand them back', function () {
    modelSays('An answer.');

    $chat = startAgentChat('What do you do?');

    test()->withHeaders(['X-Chat-Token' => $chat['token']])
        ->postJson(route('chat.human', $chat['id']))
        ->assertOk()
        ->assertJsonPath('agent.active', false);

    expect(agentConversation()->escalation_reason)->toBe('visitor_asked');

    // A further message is not answered by the assistant.
    test()->withHeaders(['X-Chat-Token' => $chat['token']])
        ->postJson(route('chat.send', $chat['id']), ['body' => 'Still there?'])
        ->assertCreated();

    expect(agentConversation()->agent_replies)->toBe(1);
});

it('takes the chat off the assistant when a member of staff replies, and lets them mark an answer wrong', function () {
    modelSays('An answer that is not quite right.');

    startAgentChat('What do you do?');
    $conversation = agentConversation();

    $staff = User::factory()->staff()->withTwoFactor()->create();
    $staff->assignRole('Support');

    $answer = $conversation->messages()->where('sender', ChatMessage::SENDER_AGENT)->latest('id')->firstOrFail();

    Livewire::actingAs($staff)
        ->test(ViewChatConversation::class, ['record' => $conversation->getRouteKey()])
        ->call('flagAnswer', (string) $answer->getKey(), 'We do not do that any more.')
        ->set('reply', 'Hello, Kofi here — let me help.')
        ->call('send');

    $conversation->refresh();

    expect($conversation->handled_by)->toBe(ChatConversation::HANDLER_STAFF)
        ->and($conversation->assigned_to)->toBe($staff->getKey())
        ->and($answer->interaction->refresh()->flagged)->toBeTrue()
        ->and($answer->interaction->flag_note)->toBe('We do not do that any more.');
});

// ── The switches ─────────────────────────────────────────────────────────────

it('shows the office what the assistant did, what it cost and what it got wrong', function () {
    modelSays('An answer.', outputTokens: 500);

    startAgentChat('What do you do?');

    $staff = User::factory()->staff()->withTwoFactor()->create();
    $staff->assignRole('Support');
    // fresh(): a factory-made model has only the columns it inserted, and the
    // panel's own gate reads `suspended_at`.
    $staff = $staff->fresh();

    $interaction = AiInteraction::query()->latest('id')->firstOrFail();
    $interaction->flag($staff, 'That is out of date.');

    $this->actingAs($staff)
        ->get(AssistantPage::getUrl())
        ->assertOk()
        ->assertSee('Answering chats')
        ->assertSee('That is out of date.')
        // The spend is shown in cedis and called an estimate, not a bill.
        ->assertSee('GH₵', false)
        ->assertSeeText('not the invoice');

    // And a donor cannot open it at all.
    $this->actingAs(User::factory()->create())->get(AssistantPage::getUrl())->assertForbidden();
});

it('is off unless the flag and the setting agree', function () {
    config(['features.chat_agent' => false]);
    expect(app(ChatAgent::class)->enabled())->toBeFalse();

    config(['features.chat_agent' => true]);
    app(Settings::class)->set('agent.enabled', false);
    app(Settings::class)->flush();

    expect(app(ChatAgent::class)->enabled())->toBeFalse();
});

it('is caught by the launch check when it is switched on and hollow', function () {
    $row = fn (): ?App\Support\HealthCheck => app(LaunchChecks::class)->checks()->firstWhere('key', 'assistant');

    modelSays('An answer.');
    expect($row()->isProblem())->toBeFalse();

    // On, with no model behind it: every chat silently goes to a person.
    config(['ai.anthropic.api_key' => null]);
    expect($row()->isProblem())->toBeTrue()
        ->and($row()->advice)->toContain('ANTHROPIC_API_KEY');

    // Answering, but no longer saying that it is a machine.
    config(['ai.anthropic.api_key' => 'test-key']);
    app(Settings::class)->set('agent.disclosure_line', '');
    app(Settings::class)->flush();

    expect($row()->isProblem())->toBeTrue()
        ->and($row()->advice)->toContain('automated');
});

it('does not claim a driver it does not have', function () {
    config(['ai.anthropic.api_key' => null]);

    expect(app(AnthropicChatModel::class)->usable())->toBeFalse()
        ->and(app(ChatAgent::class)->enabled())->toBeFalse();
});
