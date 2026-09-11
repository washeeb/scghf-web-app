<?php

declare(strict_types=1);

use App\Filament\Resources\Events\Pages\CreateEvent;
use App\Filament\Resources\Events\Pages\EditEvent;
use App\Filament\Resources\Events\Pages\ListEvents;
use App\Filament\Resources\Events\RelationManagers\RegistrationsRelationManager;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\ScheduledMessage;
use App\Models\User;
use App\Policies\BasePolicy;
use App\Support\Settings;
use App\Support\ThemeTokens;
use Database\Seeders\MessageTemplateSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\ThemeSettingsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Events
|--------------------------------------------------------------------------
|
| EVENTS AND THEIR REGISTRATIONS HAVE EXISTED SINCE PHASE 3 with no screen
| that could create one and no page that could show one. The confirmation
| email was seeded and never sent; `Event::cancel()` promised in its own
| docblock that everybody registered would be told, and nothing told them.
|
| PHOTOGRAPHY CONSENT IS ASKED, NOT ASSUMED. The column is nullable with no
| default so "we never asked" is distinguishable from "no", and the form
| requires an answer.
|
*/

beforeEach(function () {
    $this->seed(SettingsSeeder::class);
    $this->seed(ThemeSettingsSeeder::class);
    $this->seed(MessageTemplateSeeder::class);

    ThemeTokens::flush();
    app(Settings::class)->flush();
});

/** @param array<string, mixed> $overrides */
function registrationPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Ama Mensah',
        'email' => 'ama@example.test',
        'guests' => 0,
        'photography_consent' => '1',
        'consent' => '1',
    ], $overrides);
}

function eventsManager(): User
{
    test()->seed(RoleAndPermissionSeeder::class);
    BasePolicy::forgetKnownPermissions();

    $user = User::factory()->staff()->create();
    $user->givePermissionTo(['events.view', 'events.manage', 'events.view_registrations', 'media.view']);

    return $user->fresh();
}

// ── The pages ───────────────────────────────────────────────────────────────

it('turns events off with the feature flag', function () {
    config(['features.events' => false]);

    $this->get(route('events.index'))->assertNotFound();
});

it('lists what is coming, and what happened, and not what is unpublished', function () {
    $soon = Event::factory()->create(['title' => 'Harvest Outreach']);
    $past = Event::factory()->past()->create(['title' => 'Last Year\'s Clinic']);
    Event::factory()->create(['title' => 'Draft Vigil', 'is_published' => false]);

    $this->get(route('events.index'))
        ->assertOk()
        ->assertSee('Harvest Outreach')
        ->assertSee('Coming up')
        ->assertSee('Last Year')
        ->assertSee('What we have done')
        ->assertDontSee('Draft Vigil');

    $this->get(route('events.show', $soon))->assertOk()->assertSee('Register to come');
    $this->get(route('events.show', $past))->assertOk()->assertDontSee('Register to come');
});

it('does not show an unpublished event', function () {
    $event = Event::factory()->create(['is_published' => false]);

    $this->get(route('events.show', $event))->assertNotFound();
});

it('carries schema.org event data and a directions link, but never the join link', function () {
    $event = Event::factory()->create(['address' => '12 Ring Road']);
    $online = Event::factory()->create(['is_online' => true, 'online_url' => 'https://meet.example.test/secret-room']);

    $this->get(route('events.show', $event))
        ->assertOk()
        ->assertSee('"@type":"Event"', escape: false)
        ->assertSee('Get directions')
        ->assertSee('maps/search', escape: false);

    $this->get(route('events.show', $online))
        ->assertOk()
        ->assertDontSee('secret-room');
});

// ── Registering ─────────────────────────────────────────────────────────────

it('registers somebody, counts their guests, and sends the confirmation', function () {
    $event = Event::factory()->create(['capacity' => 10]);

    $this->post(route('events.register', $event), registrationPayload(['guests' => 2]))
        ->assertRedirect();

    $registration = EventRegistration::first();

    expect($registration->status)->toBe(EventRegistration::STATUS_REGISTERED)
        ->and($registration->headcount())->toBe(3)
        ->and($event->fresh()->registered_count)->toBe(3)
        ->and($registration->photography_consent)->toBeTrue()
        ->and($registration->consent_text)->not->toBeNull()
        ->and($registration->newsletter_consent)->toBeFalse();

    $message = ScheduledMessage::where('template_key', 'event.registration_confirmed')->first();

    expect($message)->not->toBeNull()
        ->and($message->to_address)->toBe('ama@example.test')
        ->and($message->payload['status'])->toContain('confirmed');

    $this->get(route('events.registered', $registration))
        ->assertOk()
        ->assertSee('You are registered')
        ->assertSee($registration->reference);
});

it('records "no" to photography as a no, not as unasked', function () {
    $event = Event::factory()->create();

    $this->post(route('events.register', $event), registrationPayload(['photography_consent' => '0']));

    expect(EventRegistration::first()->photography_consent)->toBeFalse()
        ->and(EventRegistration::first()->wasAskedAboutPhotography())->toBeTrue();
});

it('will not register somebody who was not asked about photography', function () {
    $event = Event::factory()->create();

    $this->post(route('events.register', $event), registrationPayload(['photography_consent' => null]))
        ->assertSessionHasErrors('photography_consent');

    expect(EventRegistration::count())->toBe(0);
});

it('waitlists rather than refuses once the event is full', function () {
    $event = Event::factory()->create(['capacity' => 2]);

    $this->post(route('events.register', $event), registrationPayload(['email' => 'one@example.test', 'guests' => 1]));
    $this->post(route('events.register', $event), registrationPayload(['email' => 'two@example.test']));

    $second = EventRegistration::where('email', 'two@example.test')->first();

    expect($second->status)->toBe(EventRegistration::STATUS_WAITLISTED)
        ->and($event->fresh()->registered_count)->toBe(2);

    $this->get(route('events.show', $event))->assertSee('Join the waiting list');
    $this->get(route('events.registered', $second))->assertSee('waiting list');
});

it('updates rather than duplicates a second registration from the same address', function () {
    $event = Event::factory()->create();

    $this->post(route('events.register', $event), registrationPayload());
    $this->post(route('events.register', $event), registrationPayload(['name' => 'Ama Mensah-Boateng', 'phone' => '0241234567']))
        ->assertRedirect()
        ->assertSessionHas('status');

    expect(EventRegistration::count())->toBe(1)
        ->and(EventRegistration::first()->name)->toBe('Ama Mensah-Boateng')
        ->and(EventRegistration::first()->phone)->toBe('0241234567');
});

it('refuses a registration once registration has closed, with the reason', function () {
    $event = Event::factory()->create(['registration_closes_at' => now()->subHour()]);

    $this->get(route('events.show', $event))->assertOk()->assertSee('Registration closed');

    $this->post(route('events.register', $event), registrationPayload())
        ->assertSessionHasErrors('registration');
});

// ── The admin ───────────────────────────────────────────────────────────────

it('lets the events team create an event', function () {
    $this->actingAs(eventsManager());

    Livewire::test(CreateEvent::class)
        ->fillForm([
            'title' => 'Bolgatanga Medical Outreach',
            'slug' => 'bolgatanga-medical-outreach',
            'event_type' => 'outreach',
            'starts_at' => now()->addMonth()->format('Y-m-d H:i:s'),
            'registration_required' => true,
            'capacity' => 120,
            'accessibility_notes' => 'Step-free entrance. No accessible toilet.',
            'is_published' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Event::where('slug', 'bolgatanga-medical-outreach')->exists())->toBeTrue();

    Livewire::test(ListEvents::class)->assertOk();
});

it('refuses a ticketed event while ticketing is switched off', function () {
    expect(fn () => Event::factory()->create(['is_ticketed' => true, 'ticket_price' => 5_000]))
        ->toThrow(RuntimeException::class, 'FEATURE_EVENT_TICKETING');
});

it('cancels with a reason and tells everybody who was coming', function () {
    $this->actingAs(eventsManager());
    $event = Event::factory()->create();

    $this->post(route('events.register', $event), registrationPayload(['email' => 'one@example.test']));
    $this->post(route('events.register', $event), registrationPayload(['email' => 'two@example.test']));
    EventRegistration::where('email', 'two@example.test')->first()->cancel();

    Livewire::test(ListEvents::class)
        ->callAction(TestAction::make('cancel')->table($event), data: ['reason' => 'The venue flooded overnight.'])
        ->assertHasNoActionErrors();

    expect($event->fresh()->status)->toBe(Event::STATUS_CANCELLED)
        ->and($event->fresh()->cancellation_reason)->toBe('The venue flooded overnight.')
        ->and(ScheduledMessage::where('template_key', 'event.cancelled')->count())->toBe(1)
        ->and(ScheduledMessage::where('template_key', 'event.cancelled')->first()->to_address)->toBe('one@example.test');

    $this->get(route('events.show', $event))->assertSee('cancelled')->assertSee('flooded');
});

it('shows the door list only to somebody allowed to see registrations', function () {
    $event = Event::factory()->create();
    $this->post(route('events.register', $event), registrationPayload(['accessibility_needs' => 'Wheelchair user']));

    $this->actingAs(eventsManager());

    Livewire::test(RegistrationsRelationManager::class, ['ownerRecord' => $event, 'pageClass' => EditEvent::class])
        ->assertOk()
        ->assertSee('Ama Mensah')
        ->assertSee('Wheelchair user');

    $editorOnly = User::factory()->staff()->create();
    $editorOnly->givePermissionTo(['events.view', 'events.manage']);

    expect(RegistrationsRelationManager::canViewForRecord($event, EditEvent::class))->toBeTrue();

    $this->actingAs($editorOnly->fresh());

    expect(RegistrationsRelationManager::canViewForRecord($event, EditEvent::class))->toBeFalse();
});

it('lists events in the sitemap alongside the programmatic pages', function () {
    setting()->set('seo.allow_indexing', true);
    app(Settings::class)->flush();

    $event = Event::factory()->create();

    $this->get('/sitemap.xml')
        ->assertOk()
        ->assertSee(route('events.show', $event), escape: false)
        ->assertSee(route('events.index'), escape: false)
        ->assertSee(route('donate'), escape: false);
});
