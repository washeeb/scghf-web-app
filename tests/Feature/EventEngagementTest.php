<?php

declare(strict_types=1);

use App\Community\TicketQr;
use App\Filament\Pages\DoorPage;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Gallery;
use App\Models\IssuedTicket;
use App\Models\ScheduledMessage;
use App\Models\User;
use App\Policies\BasePolicy;
use App\Support\Settings;
use App\Support\ThemeTokens;
use Database\Seeders\CmsReferenceSeeder;
use Database\Seeders\MessageTemplateSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\ThemeSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 11 — events: the reminder, the QR ticket, the door, the archive
|--------------------------------------------------------------------------
|
| `event.reminder` was seeded in Phase 3 and nothing sent it. A ticket was
| a code in an email with no square to scan. A past event showed nothing
| of what happened at it.
|
*/

beforeEach(function () {
    $this->seed(SettingsSeeder::class);
    $this->seed(ThemeSettingsSeeder::class);
    $this->seed(MessageTemplateSeeder::class);
    $this->seed(RoleAndPermissionSeeder::class);
    $this->seed(CmsReferenceSeeder::class);

    BasePolicy::forgetKnownPermissions();
    ThemeTokens::flush();
    app(Settings::class)->flush();
});

function doorSteward(): User
{
    $user = User::factory()->staff()->withTwoFactor()->create();
    $user->givePermissionTo(['events.view', 'events.view_registrations']);

    return $user->fresh();
}

function registrationFor(Event $event, array $overrides = []): EventRegistration
{
    return EventRegistration::place($event, array_merge([
        'name' => 'Ama Mensah', 'email' => 'ama@example.test', 'phone' => '+233241234567', 'guests' => 0,
        'contact_consent' => true, 'photography_consent' => true, 'newsletter_consent' => false,
        'consent_text' => 'I agree.', 'consent_ip' => '127.0.0.1',
    ], $overrides));
}

// ── Reminders ───────────────────────────────────────────────────────────────

it('reminds the people coming tomorrow, once, by email and SMS, expiring at the start', function () {
    $tomorrow = Event::factory()->create(['starts_at' => now()->addDay()->setTime(15, 0), 'ends_at' => now()->addDay()->setTime(18, 0)]);
    $nextWeek = Event::factory()->create(['starts_at' => now()->addWeek(), 'ends_at' => now()->addWeek()->addHours(2)]);

    $coming = registrationFor($tomorrow);
    $noContact = registrationFor($tomorrow, ['email' => 'quiet@example.test', 'phone' => null, 'contact_consent' => false]);
    registrationFor($nextWeek, ['email' => 'later@example.test']);

    $this->artisan('scghf:event-reminders')->assertSuccessful()->expectsOutputToContain('DRY RUN');
    expect(ScheduledMessage::count())->toBe(0);

    $this->artisan('scghf:event-reminders', ['--execute' => true])->assertSuccessful();
    $this->artisan('scghf:event-reminders', ['--execute' => true])->assertSuccessful();

    $emails = ScheduledMessage::where('template_key', 'event.reminder')->where('channel', 'email')->get();
    $texts = ScheduledMessage::where('template_key', 'event.reminder')->where('channel', 'sms')->get();

    expect($emails)->toHaveCount(1)
        ->and($emails->first()->to_address)->toBe('ama@example.test')
        ->and($emails->first()->expires_at?->equalTo($tomorrow->starts_at))->toBeTrue()
        ->and($texts)->toHaveCount(1)
        ->and($texts->first()->to_address)->toBe('+233241234567')
        ->and($tomorrow->fresh()->reminders_sent_at)->not->toBeNull()
        ->and($nextWeek->fresh()->reminders_sent_at)->toBeNull()
        ->and(ScheduledMessage::where('to_address', 'quiet@example.test')->exists())->toBeFalse();
});

// ── Tickets ─────────────────────────────────────────────────────────────────

it('shows a ticket with its QR on a signed page, and refuses the page unsigned', function () {
    $event = Event::factory()->create(['title' => 'Harvest Concert']);
    $registration = registrationFor($event);
    $ticket = IssuedTicket::create(['event_id' => $event->id, 'event_registration_id' => $registration->id, 'holder_name' => 'Ama Mensah', 'seq' => 1]);

    $this->get(route('tickets.show', $ticket))->assertForbidden();

    $this->get($ticket->url())
        ->assertOk()
        ->assertSee('Harvest Concert')
        ->assertSee($ticket->code)
        ->assertSee('<svg', escape: false);

    $this->get(URL::signedRoute('tickets.qr', ['ticket' => $ticket->code]))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/svg+xml');

    expect(app(TicketQr::class)->svg($ticket))->toContain('<svg')
        ->and(TicketQr::doorUrl($ticket))->toContain('/door/'.$ticket->code);
});

it('opens the door page from a scanned code and admits once', function () {
    $this->actingAs(doorSteward());
    $event = Event::factory()->create();
    $registration = registrationFor($event);
    $ticket = IssuedTicket::create(['event_id' => $event->id, 'event_registration_id' => $registration->id, 'holder_name' => 'Ama Mensah', 'seq' => 1]);

    Livewire::test(DoorPage::class, ['code' => $ticket->code])
        ->assertOk()
        ->assertSee($ticket->code)
        ->assertSee('Valid — admit.')
        ->callAction('admit')
        ->assertNotified('In.');

    $ticket->refresh();
    expect($ticket->checked_in_at)->not->toBeNull()
        ->and($registration->fresh()->status)->toBe(EventRegistration::STATUS_ATTENDED);

    Livewire::test(DoorPage::class, ['code' => strtolower($ticket->code)])
        ->assertSee('ALREADY USED')
        ->assertActionHidden('admit');

    Livewire::test(DoorPage::class)
        ->set('code', 'NOPE123')
        ->call('lookUp')
        ->assertSee('Nothing matches');
});

it('admits a free registration by its reference at the same door', function () {
    $this->actingAs(doorSteward());
    $registration = registrationFor(Event::factory()->create());

    Livewire::test(DoorPage::class, ['code' => $registration->reference])
        ->assertSee('Registered — admit.')
        ->callAction('admit');

    expect($registration->fresh()->status)->toBe(EventRegistration::STATUS_ATTENDED);
});

it('keeps the door from anybody without events.view_registrations', function () {
    $this->actingAs(User::factory()->staff()->withTwoFactor()->create());

    expect(DoorPage::canAccess())->toBeFalse();
});

// ── The archive ─────────────────────────────────────────────────────────────

it('shows what happened at a past event: the headcount, the outcomes and the photographs', function () {
    $gallery = Gallery::create(['title' => 'Harvest Concert 2026', 'slug' => 'harvest-2026', 'is_published' => true, 'has_consent' => true]);
    $event = Event::factory()->create([
        'starts_at' => now()->subMonth(), 'ends_at' => now()->subMonth()->addHours(3),
        'status' => Event::STATUS_COMPLETED,
        'outcomes' => '<p>GH₵ 12,400 raised for the school kits.</p>',
        'attendance_count' => 214,
        'gallery_id' => $gallery->id,
    ]);

    $this->get(route('events.show', $event))
        ->assertOk()
        ->assertSee('What happened')
        ->assertSee('214 people came.')
        ->assertSee('12,400')
        ->assertSee('All the photographs');

    $upcoming = Event::factory()->create(['outcomes' => '<p>Not yet.</p>']);
    $this->get(route('events.show', $upcoming))->assertOk()->assertDontSee('What happened');
});
