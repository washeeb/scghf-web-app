<?php

declare(strict_types=1);

use App\Community\EnquiryKinds;
use App\Filament\Resources\VolunteerApplications\Pages\ListVolunteerApplications;
use App\Filament\Resources\VolunteerApplications\Pages\ViewVolunteerApplication;
use App\Filament\Resources\VolunteerApplications\RelationManagers\ChecksRelationManager;
use App\Filament\Resources\VolunteerOpportunities\Pages\CreateVolunteerOpportunity;
use App\Models\ContactMessage;
use App\Models\Page;
use App\Models\SafeguardingCheck;
use App\Models\ScheduledMessage;
use App\Models\User;
use App\Models\Volunteer;
use App\Models\VolunteerApplication;
use App\Models\VolunteerOpportunity;
use App\Policies\BasePolicy;
use App\Support\Settings;
use App\Support\ThemeTokens;
use Database\Seeders\BlockTypeSeeder;
use Database\Seeders\CmsReferenceSeeder;
use Database\Seeders\MessageTemplateSeeder;
use Database\Seeders\PageContentSeeder;
use Database\Seeders\PageSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\ThemeSettingsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Getting involved
|--------------------------------------------------------------------------
|
| VOLUNTEERING HAS HAD A TABLE, A CHECK LEDGER AND A WORKFLOW SINCE PHASE 3
| with no admin screen, no application form, and a permission —
| `volunteers.view_pii` — that gated nothing. Approval refuses while any
| safeguarding check is outstanding, and that refusal is the point.
|
| THE ENQUIRY FORMS ARE A BLOCK, so the get-involved pages stay CMS-composed.
| Each lands in the contact inbox under the right department with its answers
| written under headings, so what arrives is actionable.
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

/** @param array<string, mixed> $overrides */
function applicationPayload(array $overrides = []): array
{
    return array_merge([
        'full_name' => 'Kwame Asante',
        'email' => 'kwame@example.test',
        'phone' => '0241234567',
        'region' => 'Upper East',
        'date_of_birth' => now()->subYears(30)->toDateString(),
        'motivation' => 'I grew up in Bolgatanga and want to give something back.',
        'skills' => 'Frafra and Twi; a driving licence.',
        'referees' => [
            ['name' => 'Rev. Atia', 'relationship' => 'Pastor', 'phone' => '0201112222', 'email' => ''],
            ['name' => 'Mrs Abugri', 'relationship' => 'Former employer', 'phone' => '', 'email' => 'abugri@example.test'],
        ],
        'declaration' => '1',
    ], $overrides);
}

function role(array $attributes = []): VolunteerOpportunity
{
    return VolunteerOpportunity::create(array_merge([
        'title' => 'Outreach Assistant',
        'slug' => 'outreach-assistant',
        'summary' => 'Help at weekend outreaches in the Upper East.',
        'is_published' => true,
    ], $attributes));
}

function volunteerCoordinator(bool $pii = true): User
{
    test()->seed(RoleAndPermissionSeeder::class);
    BasePolicy::forgetKnownPermissions();

    $user = User::factory()->staff()->create();
    $user->givePermissionTo(array_filter(['volunteers.view', 'volunteers.manage', $pii ? 'volunteers.view_pii' : null]));

    return $user->fresh();
}

// ── The pages ───────────────────────────────────────────────────────────────

it('turns volunteering off with its flag', function () {
    config(['features.volunteers' => false]);

    $this->get(route('volunteer.index'))->assertNotFound();
});

it('lists open roles, always offers a general application, and names the checks first', function () {
    role();
    role(['title' => 'Closed Role', 'slug' => 'closed-role', 'closes_on' => now()->subDay()]);
    role(['title' => 'Draft Role', 'slug' => 'draft-role', 'is_published' => false]);

    $this->get(route('volunteer.index'))
        ->assertOk()
        ->assertSee('Outreach Assistant')
        ->assertDontSee('Closed Role')
        ->assertDontSee('Draft Role')
        ->assertSee(route('volunteer.general'), escape: false);

    $this->get(route('volunteer.show', 'outreach-assistant'))
        ->assertOk()
        ->assertSee('Ghana Police Service criminal record check')
        ->assertSee('Send my application');

    $this->get(route('volunteer.show', 'closed-role'))->assertOk()->assertSee('have closed');
    $this->get(route('volunteer.show', 'draft-role'))->assertNotFound();
});

it('does not ask for a date of birth when the role has no vulnerable contact', function () {
    role(['involves_vulnerable_contact' => false]);

    $this->get(route('volunteer.show', 'outreach-assistant'))
        ->assertOk()
        ->assertDontSee('Date of birth')
        ->assertDontSee('Ghana Police Service');
});

// ── Applying ────────────────────────────────────────────────────────────────

it('takes an application, submits it, opens the checks and acknowledges it', function () {
    $role = role();

    $this->post(route('volunteer.apply', $role), applicationPayload())->assertRedirect();

    $application = VolunteerApplication::first();

    expect($application->status)->toBe(VolunteerApplication::STATUS_SUBMITTED)
        ->and($application->declaration_agreed)->toBeTrue()
        ->and($application->declaration_text)->toContain('safeguarding policy')
        ->and($application->declaration_at)->not->toBeNull()
        ->and($application->checks()->count())->toBe(5)
        ->and($application->outstandingChecks())->toHaveCount(5);

    expect(ScheduledMessage::where('template_key', 'volunteer.application_received')->first()?->to_address)
        ->toBe('kwame@example.test');

    $this->get(route('volunteer.applied', $application))
        ->assertOk()
        ->assertSee($application->reference)
        ->assertSee('safeguarding checks');
});

it('refuses an application without the declaration, or from somebody under 18', function () {
    $role = role();

    $this->post(route('volunteer.apply', $role), applicationPayload(['declaration' => '0']))
        ->assertSessionHasErrors('declaration');

    $this->post(route('volunteer.apply', $role), applicationPayload(['date_of_birth' => now()->subYears(16)->toDateString()]))
        ->assertSessionHasErrors('date_of_birth');

    expect(VolunteerApplication::count())->toBe(0);
});

it('takes a general application against no role, with the full check set', function () {
    $this->post(route('volunteer.apply.general'), applicationPayload())->assertRedirect();

    expect(VolunteerApplication::first()->volunteer_opportunity_id)->toBeNull()
        ->and(VolunteerApplication::first()->checks()->count())->toBe(5);
});

// ── Reviewing ───────────────────────────────────────────────────────────────

it('refuses to approve while a check is outstanding, and approves once they are recorded', function () {
    $role = role();
    $this->post(route('volunteer.apply', $role), applicationPayload());
    $application = VolunteerApplication::first();

    $this->actingAs(volunteerCoordinator());

    Livewire::test(ViewVolunteerApplication::class, ['record' => $application->getRouteKey()])
        ->assertOk()
        ->callAction('approve', data: ['role' => 'Outreach Assistant']);

    expect($application->fresh()->status)->toBe(VolunteerApplication::STATUS_SUBMITTED)
        ->and(Volunteer::count())->toBe(0);

    // Record every check through the relation manager.
    $manager = Livewire::test(ChecksRelationManager::class, [
        'ownerRecord' => $application,
        'pageClass' => ViewVolunteerApplication::class,
    ])->assertOk();

    foreach ($application->checks as $check) {
        $manager->callAction(TestAction::make('pass')->table($check), data: [
            'reference' => 'Ref for '.$check->check_type,
            'completed_on' => now()->toDateString(),
        ])->assertHasNoActionErrors();
    }

    expect($application->fresh()->outstandingChecks())->toBe([])
        ->and($application->checks()->first()->verified_by)->not->toBeNull();

    Livewire::test(ViewVolunteerApplication::class, ['record' => $application->getRouteKey()])
        ->callAction('approve', data: ['role' => 'Outreach Assistant'])
        ->assertHasNoActionErrors();

    expect($application->fresh()->status)->toBe(VolunteerApplication::STATUS_APPROVED)
        ->and(Volunteer::count())->toBe(1)
        ->and($role->fresh()->positions_filled)->toBe(1)
        ->and(ScheduledMessage::where('template_key', 'volunteer.approved')->where('channel', 'email')->exists())->toBeTrue()
        ->and(ScheduledMessage::where('template_key', 'volunteer.approved')->where('channel', 'sms')->exists())->toBeTrue();
});

it('will not record a check as passed without a reference', function () {
    $role = role();
    $this->post(route('volunteer.apply', $role), applicationPayload());
    $application = VolunteerApplication::first();

    $this->actingAs(volunteerCoordinator());

    Livewire::test(ChecksRelationManager::class, ['ownerRecord' => $application, 'pageClass' => ViewVolunteerApplication::class])
        ->callAction(TestAction::make('pass')->table($application->checks->first()), data: ['reference' => ''])
        ->assertHasActionErrors(['reference']);

    expect($application->checks()->where('outcome', SafeguardingCheck::OUTCOME_PASSED)->count())->toBe(0);
});

it('declines with a reason for the file and a message for the applicant', function () {
    $role = role();
    $this->post(route('volunteer.apply', $role), applicationPayload());
    $application = VolunteerApplication::first();

    $this->actingAs(volunteerCoordinator());

    Livewire::test(ViewVolunteerApplication::class, ['record' => $application->getRouteKey()])
        ->callAction('decline', data: [
            'reason' => 'Second reference could not be taken up.',
            'message' => 'Thank you for applying. We are not able to take your application further at this time.',
        ])
        ->assertHasNoActionErrors();

    expect($application->fresh()->status)->toBe(VolunteerApplication::STATUS_DECLINED)
        ->and($application->fresh()->decline_reason)->toBe('Second reference could not be taken up.');

    $email = ScheduledMessage::where('template_key', 'volunteer.declined')->first();

    expect($email->payload['message'])->toContain('not able to take')
        ->and($email->payload)->not->toHaveKey('reason');
});

it('shows the personal details only to somebody with the PII permission', function () {
    $role = role();
    $this->post(route('volunteer.apply', $role), applicationPayload(['disclosed_convictions' => 'A caution in 2014 for a traffic offence.']));
    $application = VolunteerApplication::first();

    $this->actingAs(volunteerCoordinator(pii: false));

    Livewire::test(ViewVolunteerApplication::class, ['record' => $application->getRouteKey()])
        ->assertOk()
        ->assertSee('Kwame Asante')
        ->assertDontSee('traffic offence');

    $this->actingAs(volunteerCoordinator(pii: true));

    Livewire::test(ViewVolunteerApplication::class, ['record' => $application->getRouteKey()])
        ->assertOk()
        ->assertSee('traffic offence');

    Livewire::test(ListVolunteerApplications::class)->assertOk()->assertSee('Kwame Asante');
});

it('lets the coordinator create a role, with contact assumed', function () {
    $this->actingAs(volunteerCoordinator());

    Livewire::test(CreateVolunteerOpportunity::class)
        ->fillForm([
            'title' => 'Homework Club Helper',
            'slug' => 'homework-club-helper',
            'placement_type' => VolunteerOpportunity::PLACEMENT_FIELD,
            'is_published' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(VolunteerOpportunity::where('slug', 'homework-club-helper')->first()->involves_vulnerable_contact)->toBeTrue();
});

// ── The enquiry forms ───────────────────────────────────────────────────────

it('renders each enquiry form from its block and files the answers under the right department', function () {
    $this->seed(BlockTypeSeeder::class);
    $this->seed(PageSeeder::class);
    $this->seed(PageContentSeeder::class);

    $page = Page::where('slug', 'donate-goods')->firstOrFail();
    $page->publish();

    $this->get($page->path)
        ->assertOk()
        ->assertSee('What are you offering?')
        ->assertSee(route('enquiries.store', 'in-kind'), escape: false);

    $this->from($page->path)->post(route('enquiries.store', 'in-kind'), [
        'name' => 'Abena Owusu',
        'email' => 'abena@example.test',
        'goods' => '40 exercise books and 20 school bags',
        'quantity' => 'Two cartons',
        'condition' => 'new',
        'location' => 'Tamale, Northern',
        'transport' => 'collect',
        'consent' => '1',
    ])->assertRedirect($page->path)->assertSessionHas('status');

    $message = ContactMessage::first();

    expect($message->department->key)->toBe('donations')
        ->and($message->subject)->toBe('In-kind offer')
        ->and($message->message)->toContain("What are you offering?:\n40 exercise books")
        ->and($message->message)->toContain("Condition:\nNew")
        ->and($message->message)->toContain('It would need collecting')
        ->and(ScheduledMessage::where('template_key', 'contact.acknowledgement')->exists())->toBeTrue();
});

it('validates an enquiry against its kind and refuses an unknown kind', function () {
    $this->post(route('enquiries.store', 'partner'), ['name' => 'X', 'email' => 'x@example.test', 'consent' => '1'])
        ->assertSessionHasErrors(['organisation', 'proposal']);

    $this->post(route('enquiries.store', 'corporate'), ['name' => 'X', 'email' => 'x@example.test', 'organisation' => 'Acme', 'interest' => 'not-a-choice', 'proposal' => 'Hi', 'consent' => '1'])
        ->assertSessionHasErrors(['interest']);

    $this->post(route('enquiries.store', 'ransomware'), ['name' => 'X', 'email' => 'x@example.test', 'consent' => '1'])
        ->assertNotFound();

    expect(ContactMessage::count())->toBe(0);
});

it('seeds a first draft for every get-involved page, as drafts, and never overwrites', function () {
    $this->seed(BlockTypeSeeder::class);
    $this->seed(PageSeeder::class);
    $this->seed(PageContentSeeder::class);

    foreach (['get-involved', 'partner-with-us', 'corporate-giving', 'donate-goods', 'fundraise-for-us'] as $slug) {
        $page = Page::where('slug', $slug)->firstOrFail();

        expect($page->sections()->count())->toBeGreaterThan(0, $slug)
            ->and($page->isLive())->toBeFalse($slug);
    }

    $page = Page::where('slug', 'partner-with-us')->firstOrFail();
    $page->sections()->delete();
    $page->sections()->create(['block_type' => 'rich-text', 'data' => ['body' => '<p>Edited by a person.</p>']]);

    $this->seed(PageContentSeeder::class);

    expect($page->sections()->count())->toBe(1);

    expect(array_keys(EnquiryKinds::all()))->toBe(['partner', 'corporate', 'in-kind', 'fundraise']);
});
