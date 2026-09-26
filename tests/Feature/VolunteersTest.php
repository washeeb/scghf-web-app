<?php

declare(strict_types=1);

use App\Filament\Resources\VolunteerApplications\Pages\ViewVolunteerApplication;
use App\Filament\Resources\Volunteers\Pages\ListVolunteers;
use App\Filament\Resources\Volunteers\Pages\ViewVolunteer;
use App\Filament\Resources\Volunteers\RelationManagers\HoursRelationManager;
use App\Filament\Resources\Volunteers\RelationManagers\ShiftsRelationManager;
use App\Filament\Resources\Volunteers\VolunteerResource;
use App\Models\ScheduledMessage;
use App\Models\User;
use App\Models\Volunteer;
use App\Models\VolunteerApplication;
use App\Models\VolunteerHour;
use App\Models\VolunteerOpportunity;
use App\Models\VolunteerShift;
use App\Policies\BasePolicy;
use App\Support\Settings;
use App\Support\ThemeTokens;
use Database\Seeders\CmsReferenceSeeder;
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
| Phase 11 — volunteers, after the application
|--------------------------------------------------------------------------
|
| Phase 6 built the application and the safeguarding gate. This is what the
| brief asked for on top: two referees the checks can actually take up,
| the shortlist and interview stages with their messages, the Volunteers
| screen the model never had, hours that `volunteers.log_hours` finally
| protects, shifts with the reminder Phase 10 deferred, and a thank-you
| that says the hours back.
|
*/

beforeEach(function () {
    $this->seed(SettingsSeeder::class);
    $this->seed(ThemeSettingsSeeder::class);
    $this->seed(MessageTemplateSeeder::class);
    $this->seed(RoleAndPermissionSeeder::class);

    BasePolicy::forgetKnownPermissions();
    ThemeTokens::flush();
    app(Settings::class)->flush();
});

function volunteerStaff(array $permissions): User
{
    $user = User::factory()->staff()->withTwoFactor()->create();
    $user->givePermissionTo($permissions);

    return $user->fresh();
}

function submittedApplication(): VolunteerApplication
{
    $application = VolunteerApplication::factory()->create([
        'volunteer_opportunity_id' => VolunteerOpportunity::factory()->create(['title' => 'Reading club helper'])->id,
        'email' => 'ama@example.test',
        'phone' => '+233241234567',
    ]);
    $application->submit();

    return $application->fresh()->load('opportunity');
}

// ── The application ─────────────────────────────────────────────────────────

it('requires two reachable referees for a role with vulnerable contact, and none otherwise', function () {
    $this->seed(CmsReferenceSeeder::class);
    $contact = VolunteerOpportunity::factory()->create(['is_published' => true, 'involves_vulnerable_contact' => true]);
    $steward = VolunteerOpportunity::factory()->create(['is_published' => true, 'involves_vulnerable_contact' => false, 'slug' => 'steward']);

    $base = [
        'full_name' => 'Kwame Asante', 'email' => 'kwame@example.test', 'phone' => '0241234567',
        'date_of_birth' => now()->subYears(30)->toDateString(), 'motivation' => 'To help.', 'declaration' => '1',
    ];

    $this->post(route('volunteer.apply', $contact), $base)
        ->assertSessionHasErrors(['referees.0.name', 'referees.1.name']);

    $this->post(route('volunteer.apply', $contact), $base + ['referees' => [
        ['name' => 'Rev. Atia', 'relationship' => 'Pastor'],
        ['name' => 'Mrs Abugri', 'phone' => '0201112222'],
    ]])->assertSessionHasErrors(['referees.0.phone', 'referees.0.email']);

    $this->post(route('volunteer.apply', $contact), $base + ['skills' => 'Twi, first aid', 'referees' => [
        ['name' => 'Rev. Atia', 'relationship' => 'Pastor', 'phone' => '0201112222'],
        ['name' => 'Mrs Abugri', 'email' => 'abugri@example.test'],
    ]])->assertSessionHasNoErrors();

    $application = VolunteerApplication::firstOrFail();
    expect($application->referees())->toHaveCount(2)
        ->and($application->referees()[0]['name'])->toBe('Rev. Atia')
        ->and($application->referees()[1]['email'])->toBe('abugri@example.test')
        ->and($application->skills)->toBe('Twi, first aid');

    $this->post(route('volunteer.apply', $steward), $base + ['email' => 'other@example.test'])->assertSessionHasNoErrors();
});

it('shows the skills a role needs on its page', function () {
    $this->seed(CmsReferenceSeeder::class);
    $role = VolunteerOpportunity::factory()->create(['is_published' => true, 'skills_needed' => ['Frafra', 'first aid']]);

    $this->get(route('volunteer.show', $role))->assertOk()->assertSee('Skills that help')->assertSee('Frafra')->assertSee('first aid');
});

// ── The stages ──────────────────────────────────────────────────────────────

it('moves an application through shortlisted and interviewed, telling the applicant each time', function () {
    $reviewer = volunteerStaff(['volunteers.view', 'volunteers.manage', 'volunteers.view_pii']);
    $this->actingAs($reviewer);
    $application = submittedApplication();

    Livewire::test(ViewVolunteerApplication::class, ['record' => $application->getRouteKey()])
        ->assertOk()
        ->callAction('shortlist')
        ->assertNotified();

    expect($application->fresh()->status)->toBe(VolunteerApplication::STATUS_SHORTLISTED)
        ->and(ScheduledMessage::where('template_key', 'volunteer.shortlisted')->where('channel', 'email')->exists())->toBeTrue()
        ->and(ScheduledMessage::where('template_key', 'volunteer.shortlisted')->where('channel', 'sms')->exists())->toBeTrue();

    $when = now()->addDays(3)->setTime(10, 0);

    Livewire::test(ViewVolunteerApplication::class, ['record' => $application->getRouteKey()])
        ->callAction('interview', ['interview_at' => $when->toDateTimeString(), 'location' => 'Tamale office'])
        ->assertNotified();

    $fresh = $application->fresh();
    expect($fresh->interview_at->equalTo($when))->toBeTrue()
        ->and($fresh->interview_location)->toBe('Tamale office');

    $invite = ScheduledMessage::where('template_key', 'volunteer.interview')->where('channel', 'email')->firstOrFail();
    expect(json_encode($invite->payload))->toContain($when->translatedFormat('l j F Y'));

    Livewire::test(ViewVolunteerApplication::class, ['record' => $application->getRouteKey()])
        ->callAction('interviewed', ['notes' => 'Warm, reliable, knows the community.'])
        ->assertNotified();

    expect($application->fresh()->status)->toBe(VolunteerApplication::STATUS_INTERVIEWED)
        ->and($application->fresh()->interview_notes)->toContain('reliable');
});

it('refuses an interview in the past and a stage on a closed application', function () {
    $reviewer = volunteerStaff(['volunteers.manage']);
    $application = submittedApplication();

    expect(fn () => $application->scheduleInterview($reviewer, now()->subDay(), 'Office'))->toThrow(RuntimeException::class);

    $application->decline($reviewer, 'No.');
    expect(fn () => $application->fresh()->shortlist($reviewer))->toThrow(RuntimeException::class, 'declined');
});

// ── The Volunteers screen ───────────────────────────────────────────────────

it('lists volunteers to volunteers.view and hides them otherwise', function () {
    Volunteer::factory()->create(['full_name' => 'Abena Owusu']);

    $this->actingAs(volunteerStaff(['volunteers.view']));
    expect(VolunteerResource::canViewAny())->toBeTrue();
    Livewire::test(ListVolunteers::class)->assertOk()->assertSee('Abena Owusu')->assertSee('active');

    $this->actingAs(volunteerStaff(['orders.view']));
    expect(VolunteerResource::canViewAny())->toBeFalse();
});

it('lets volunteers.log_hours record hours and only a different volunteers.manage verify them', function () {
    $logger = volunteerStaff(['volunteers.view', 'volunteers.log_hours']);
    $manager = volunteerStaff(['volunteers.view', 'volunteers.manage']);
    $volunteer = Volunteer::factory()->create();

    $this->actingAs($logger);
    Livewire::test(HoursRelationManager::class, ['ownerRecord' => $volunteer, 'pageClass' => ViewVolunteer::class])
        ->assertOk()
        ->callAction(TestAction::make('create')->table(), ['worked_on' => now()->toDateString(), 'hours' => 2.5, 'activity' => 'Reading club'])
        ->assertHasNoActionErrors();

    $hour = VolunteerHour::firstOrFail();
    expect($hour->minutes)->toBe(150)
        ->and($hour->recorded_by)->toBe($logger->id)
        ->and($hour->verified_at)->toBeNull()
        ->and($volunteer->fresh()->total_hours)->toBe(0);

    // The logger may not verify their own entry, and has no manage anyway.
    expect(fn () => $hour->verify($logger))->toThrow(RuntimeException::class);

    $this->actingAs($manager);
    Livewire::test(HoursRelationManager::class, ['ownerRecord' => $volunteer, 'pageClass' => ViewVolunteer::class])
        ->callAction(TestAction::make('verify')->table($hour))
        ->assertNotified('Verified.');

    expect($hour->fresh()->verified_by)->toBe($manager->id)
        ->and($volunteer->fresh()->total_hours)->toBe(2);
});

it('schedules a shift, reminds the evening before once, and completing it writes the hours', function () {
    $planner = volunteerStaff(['volunteers.view', 'volunteers.manage']);
    $confirmer = volunteerStaff(['volunteers.view', 'volunteers.manage']);
    // No application behind a factory volunteer, so a vulnerable-contact role
    // is uncleared by definition; a steward's role is what can be rostered here.
    $volunteer = Volunteer::factory()->noVulnerableContact()->create(['email' => 'abena@example.test', 'phone' => '+233241234567']);

    $this->actingAs($planner);
    $starts = now()->addDay()->setTime(9, 0);

    Livewire::test(ShiftsRelationManager::class, ['ownerRecord' => $volunteer, 'pageClass' => ViewVolunteer::class])
        ->assertOk()
        ->callAction(TestAction::make('create')->table(), [
            'starts_at' => $starts->toDateTimeString(), 'ends_at' => $starts->copy()->addHours(3)->toDateTimeString(),
            'activity' => 'Saturday feeding', 'location' => 'Tamale office',
        ])
        ->assertHasNoActionErrors();

    $shift = VolunteerShift::firstOrFail();
    expect($shift->created_by)->toBe($planner->id);

    $this->artisan('scghf:shift-reminders', ['--execute' => true])->assertSuccessful();
    $this->artisan('scghf:shift-reminders', ['--execute' => true])->assertSuccessful();

    expect(ScheduledMessage::where('template_key', 'volunteer.shift_reminder')->where('channel', 'email')->count())->toBe(1)
        ->and(ScheduledMessage::where('template_key', 'volunteer.shift_reminder')->where('channel', 'sms')->count())->toBe(1)
        ->and($shift->fresh()->reminder_sent_at)->not->toBeNull();

    // The shift happens; a second person confirms it.
    $this->travelTo($starts->copy()->addHours(4));
    $this->actingAs($confirmer);

    Livewire::test(ShiftsRelationManager::class, ['ownerRecord' => $volunteer, 'pageClass' => ViewVolunteer::class])
        ->callAction(TestAction::make('complete')->table($shift), ['hours' => 3])
        ->assertNotified();

    $shift->refresh();
    expect($shift->status)->toBe(VolunteerShift::STATUS_COMPLETED)
        ->and($shift->hour)->not->toBeNull()
        ->and($shift->hour->minutes)->toBe(180)
        ->and($shift->hour->recorded_by)->toBe($planner->id)
        ->and($shift->hour->verified_by)->toBe($confirmer->id)
        ->and($volunteer->fresh()->total_hours)->toBe(3);

    // Pressed twice, written once.
    $shift->complete($confirmer);
    expect(VolunteerHour::count())->toBe(1);
});

it('does not remind a suspended or lapsed volunteer, and does not roster them', function () {
    $volunteer = Volunteer::factory()->create(['clearance_expires_on' => now()->subDay()->toDateString()]);
    VolunteerShift::factory()->create(['volunteer_id' => $volunteer->id]);

    $this->artisan('scghf:shift-reminders', ['--execute' => true])->assertSuccessful();

    expect(ScheduledMessage::where('template_key', 'volunteer.shift_reminder')->count())->toBe(0)
        ->and($volunteer->isAvailable())->toBeFalse();
});

it('records a volunteer as left and thanks them with the hours they gave', function () {
    $manager = volunteerStaff(['volunteers.view', 'volunteers.manage', 'volunteers.view_pii']);
    $this->actingAs($manager);
    $volunteer = Volunteer::factory()->create(['full_name' => 'Abena Owusu', 'email' => 'abena@example.test', 'total_hours' => 0]);
    VolunteerHour::forceCreate(['volunteer_id' => $volunteer->id, 'worked_on' => now()->subWeek()->toDateString(), 'minutes' => 300, 'verified_at' => now(), 'verified_by' => $manager->id]);

    Livewire::test(ViewVolunteer::class, ['record' => $volunteer->getRouteKey()])
        ->assertOk()
        ->assertSee('Abena Owusu')
        ->callAction('leave', ['reason' => 'Moved to Kumasi.'])
        ->assertNotified();

    $volunteer->refresh();
    $thanks = ScheduledMessage::where('template_key', 'volunteer.thank_you')->firstOrFail();

    expect($volunteer->status)->toBe(Volunteer::STATUS_LEFT)
        ->and($volunteer->total_hours)->toBe(5)
        ->and($thanks->to_address)->toBe('abena@example.test')
        ->and(json_encode($thanks->payload))->toContain('"5"');
});

it('shows verified volunteer hours on the impact page', function () {
    $this->seed(CmsReferenceSeeder::class);
    $volunteer = Volunteer::factory()->create();
    VolunteerHour::forceCreate(['volunteer_id' => $volunteer->id, 'worked_on' => now()->subWeek()->toDateString(), 'minutes' => 600, 'verified_at' => now()]);
    VolunteerHour::forceCreate(['volunteer_id' => $volunteer->id, 'worked_on' => now()->subWeek()->toDateString(), 'minutes' => 600]);

    cache()->forget('impact.figures');

    $this->get(route('impact'))->assertOk()->assertSee('Volunteer hours given')->assertSee('10')->assertSee('1 volunteer today');
});
