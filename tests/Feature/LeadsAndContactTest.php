<?php

declare(strict_types=1);

use App\Filament\Resources\Offices\OfficeResource;
use App\Filament\Resources\Offices\Pages\ListOffices;
use App\Models\ContactDepartment;
use App\Models\ContactMessage;
use App\Models\Office;
use App\Models\ScheduledMessage;
use App\Models\Setting;
use App\Models\Subscriber;
use App\Models\User;
use App\Policies\BasePolicy;
use App\Support\Settings;
use App\Support\ThemeTokens;
use Database\Seeders\CmsReferenceSeeder;
use Database\Seeders\MessageTemplateSeeder;
use Database\Seeders\OfficeSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\ThemeSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 11 — lead capture and contact
|--------------------------------------------------------------------------
|
| The popup that is off until it is on, and never on a page where money is
| being entered. The SLA badge that has told nobody since Phase 5. The
| offices the contact page lists, seeded from the one address that was
| ever configured.
|
*/

beforeEach(function () {
    $this->seed(SettingsSeeder::class);
    $this->seed(ThemeSettingsSeeder::class);
    $this->seed(MessageTemplateSeeder::class);
    $this->seed(CmsReferenceSeeder::class);

    ThemeTokens::flush();
    app(Settings::class)->flush();
});

function setSetting(string $key, string $value): void
{
    [$group, $name] = explode('.', $key, 2);
    Setting::query()->where('group', $group)->where('key', $name)->update(['value' => $value]);
    app(Settings::class)->flush();
}

// ── The popup ───────────────────────────────────────────────────────────────

it('renders the newsletter popup only when switched on, never on a money page, never after subscribing', function () {
    $this->get('/')->assertOk()->assertDontSee('data-newsletter-popup', escape: false);

    setSetting('site.newsletter_popup_enabled', '1');

    $this->get('/')->assertOk()
        ->assertSee('data-newsletter-popup', escape: false)
        ->assertSee('data-frequency-days="30"', escape: false)
        ->assertSee('Before you go')
        ->assertSee('name="source" value="popup"', escape: false)
        ->assertSee(setting('compliance.newsletter_consent_text'));

    $this->get(route('donate'))->assertOk()->assertDontSee('data-newsletter-popup', escape: false);

    $this->withCookie('scghf_subscribed', '1')->get('/')->assertOk()->assertDontSee('data-newsletter-popup', escape: false);
});

it('records where a subscriber came from and sets the subscribed cookie', function () {
    $this->post(route('newsletter.subscribe'), ['email' => 'ama@example.test', 'source' => 'popup'])
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertCookie('scghf_subscribed');

    expect(Subscriber::firstOrFail()->source)->toBe('popup');

    $this->post(route('newsletter.subscribe'), ['email' => 'kofi@example.test', 'source' => 'hacked'])
        ->assertSessionHasErrors('source');
});

// ── SLA reminders ───────────────────────────────────────────────────────────

it('reminds the owner of an enquiry past its reply target, once, and the department mailbox when there is no owner', function () {
    $this->seed(RoleAndPermissionSeeder::class);
    $owner = User::factory()->staff()->create(['email' => 'grace@example.test']);
    $fast = ContactDepartment::create(['key' => 'front-desk', 'name' => 'Front desk', 'email' => 'info@example.test', 'sla_hours' => 24]);
    $slow = ContactDepartment::create(['key' => 'press', 'name' => 'Press', 'email' => 'media@example.test', 'sla_hours' => 72]);

    $owned = ContactMessage::create(['contact_department_id' => $fast->id, 'name' => 'Ama', 'email' => 'ama@example.test', 'subject' => 'Volunteering?', 'message' => 'Hi']);
    $owned->forceFill(['assigned_to' => $owner->id, 'status' => 'assigned', 'created_at' => now()->subHours(30)])->save();

    $unowned = ContactMessage::create(['contact_department_id' => $fast->id, 'name' => 'Kofi', 'email' => 'kofi@example.test', 'message' => 'Hello']);
    $unowned->forceFill(['created_at' => now()->subHours(48)])->save();

    $fresh = ContactMessage::create(['contact_department_id' => $fast->id, 'name' => 'Yaa', 'email' => 'yaa@example.test', 'message' => 'Hi']);

    $inTime = ContactMessage::create(['contact_department_id' => $slow->id, 'name' => 'Esi', 'email' => 'esi@example.test', 'message' => 'Hi']);
    $inTime->forceFill(['created_at' => now()->subHours(30)])->save();

    $replied = ContactMessage::create(['contact_department_id' => $fast->id, 'name' => 'Abena', 'email' => 'abena@example.test', 'message' => 'Hi']);
    $replied->forceFill(['created_at' => now()->subHours(48), 'status' => 'replied', 'replied_at' => now()->subHour()])->save();

    $this->artisan('scghf:contact-sla')->assertSuccessful()->expectsOutputToContain('DRY RUN');
    expect(ScheduledMessage::count())->toBe(0);

    $this->artisan('scghf:contact-sla', ['--execute' => true])->assertSuccessful();
    $this->artisan('scghf:contact-sla', ['--execute' => true])->assertSuccessful();

    $reminders = ScheduledMessage::where('template_key', 'contact.sla_reminder')->get();

    expect($reminders)->toHaveCount(2)
        ->and($reminders->pluck('to_address')->sort()->values()->all())->toBe(['grace@example.test', 'info@example.test'])
        ->and($owned->fresh()->sla_reminded_at)->not->toBeNull()
        ->and($fresh->fresh()->sla_reminded_at)->toBeNull()
        ->and($inTime->fresh()->sla_reminded_at)->toBeNull()
        ->and($replied->fresh()->sla_reminded_at)->toBeNull()
        ->and(json_encode($reminders->firstWhere('to_address', 'grace@example.test')->payload))->toContain($owned->reference);
});

// ── Offices ─────────────────────────────────────────────────────────────────

it('seeds the first office from the contact settings, once, and lists offices on the contact page', function () {
    setSetting('contact.address', '12 Liberation Road');
    setSetting('contact.city', 'Tamale');
    setSetting('contact.whatsapp', '024 123 4567');
    setSetting('contact.office_hours', '8:00–17:00');

    $this->seed(OfficeSeeder::class);
    $this->seed(OfficeSeeder::class);

    expect(Office::count())->toBe(1);
    $office = Office::firstOrFail();
    expect($office->is_primary)->toBeTrue()
        ->and($office->whatsappUrl())->toBe('https://wa.me/233241234567')
        ->and($office->hoursRows())->toHaveCount(5)
        ->and($office->directionsUrl())->toContain('12+Liberation+Road');

    Office::create(['name' => 'Bolgatanga field office', 'city' => 'Bolgatanga', 'region' => 'Upper East', 'phone' => '020 111 2222', 'hours' => ['sat' => 'By appointment'], 'notes' => 'Behind the Total station.', 'is_active' => true]);
    Office::create(['name' => 'Closed one', 'is_active' => false]);

    $this->get(route('contact'))
        ->assertOk()
        ->assertSee('12 Liberation Road')
        ->assertSee('Main office')
        ->assertSee('Bolgatanga field office')
        ->assertSee('By appointment')
        ->assertSee('Behind the Total station.')
        ->assertSee('https://wa.me/233241234567')
        ->assertSee('Get directions')
        ->assertDontSee('Closed one');
});

it('keeps one main office and lets settings.manage edit them', function () {
    $this->seed(RoleAndPermissionSeeder::class);
    BasePolicy::forgetKnownPermissions();

    $a = Office::create(['name' => 'A', 'is_primary' => true]);
    $b = Office::create(['name' => 'B', 'is_primary' => true]);

    expect($a->fresh()->is_primary)->toBeFalse()->and($b->fresh()->is_primary)->toBeTrue();

    $user = User::factory()->staff()->withTwoFactor()->create();
    $user->givePermissionTo('settings.manage');
    $this->actingAs($user);

    expect(OfficeResource::canViewAny())->toBeTrue();
    Livewire::test(ListOffices::class)->assertOk()->assertSee('A')->assertSee('B');
});
