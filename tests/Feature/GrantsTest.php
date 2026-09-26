<?php

declare(strict_types=1);

use App\Filament\Resources\Grants\Pages\ViewGrant;
use App\Filament\Resources\Grants\RelationManagers\ObligationsRelationManager;
use App\Filament\Resources\Payouts\Pages\CreatePayout;
use App\Filament\Resources\Payouts\Pages\ViewPayout;
use App\Grants\GrantDocuments;
use App\Models\Division;
use App\Models\Funder;
use App\Models\Grant;
use App\Models\Payout;
use App\Models\Project;
use App\Models\ScheduledMessage;
use App\Models\User;
use App\Policies\BasePolicy;
use App\Support\Settings;
use App\Support\ThemeTokens;
use App\ValueObjects\Money;
use Database\Seeders\MessageTemplateSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\ThemeSettingsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Wave 2 — grants (roadmap 1.6), and the payouts screen it needed
|--------------------------------------------------------------------------
|
| Funders, the pipeline, the deadlines the funder is owed and the
| reminders before them, the papers, and spend against each award read
| from the payouts ledger. Payouts had a model, permissions and a
| two-person rule since Phase 7 and no screen; they get one here.
*/

beforeEach(function () {
    $this->seed(SettingsSeeder::class);
    $this->seed(ThemeSettingsSeeder::class);
    $this->seed(RoleAndPermissionSeeder::class);
    $this->seed(MessageTemplateSeeder::class);
    BasePolicy::forgetKnownPermissions();
    ThemeTokens::flush();
    app(Settings::class)->flush();
    Storage::fake('downloads');

    $this->finance = User::factory()->staff()->withTwoFactor()->create(['email' => 'finance@foundation.test']);
    $this->finance->assignRole('Finance Officer');
    $this->finance = $this->finance->fresh();

    $this->admin = User::factory()->staff()->withTwoFactor()->create();
    $this->admin->assignRole('Admin');
    $this->admin = $this->admin->fresh();
});

function grant(array $overrides = []): Grant
{
    return Grant::query()->create(array_merge([
        'funder_id' => Funder::query()->create(['name' => 'Bright Futures Trust', 'funder_type' => 'foundation'])->id,
        'project_id' => Project::factory()->create(['title' => 'School kits for Bongo'])->id,
        'title' => 'Bright Futures school kits 2026',
        'amount_requested' => Money::ofMinor(5_000_000),
        'deadline_on' => now()->addDays(10)->toDateString(),
        'owner_id' => test()->finance->id,
    ], $overrides));
}

// ── The pipeline ────────────────────────────────────────────────────────────

it('walks a grant from idea to awarded and refuses an award with no figure', function () {
    $grant = grant();

    expect($grant->status)->toBe(Grant::STATUS_IDEA);

    $grant->submit();
    expect($grant->fresh()->status)->toBe(Grant::STATUS_SUBMITTED)->and($grant->fresh()->submitted_on)->not->toBeNull();

    expect(fn () => $grant->award(Money::zero()))->toThrow(RuntimeException::class);

    $grant->award(Money::ofMinor(4_500_000), '2026-10-01', '2027-09-30');

    $grant->refresh();
    expect($grant->status)->toBe(Grant::STATUS_AWARDED)
        ->and($grant->amount_awarded->toMinor())->toBe(4_500_000)
        ->and($grant->decided_on)->not->toBeNull()
        ->and($grant->starts_on->toDateString())->toBe('2026-10-01');
});

it('reads spend against the award from the payouts ledger, never from a typed figure', function () {
    $grant = grant();
    $grant->award(Money::ofMinor(1_000_000));

    $paid = Payout::factory()->create(['grant_id' => $grant->id, 'project_id' => $grant->project_id, 'amount' => Money::ofMinor(300_000)]);
    $paid->forceFill(['status' => Payout::STATUS_PAID, 'paid_at' => now()])->save();

    $approved = Payout::factory()->create(['grant_id' => $grant->id, 'project_id' => $grant->project_id, 'amount' => Money::ofMinor(200_000)]);
    $approved->forceFill(['status' => Payout::STATUS_APPROVED])->save();

    Payout::factory()->create(['grant_id' => $grant->id, 'project_id' => $grant->project_id, 'amount' => Money::ofMinor(999_999)]); // a draft counts for nothing
    Payout::factory()->create(['project_id' => $grant->project_id, 'amount' => Money::ofMinor(777_777)])->forceFill(['status' => Payout::STATUS_PAID, 'paid_at' => now()])->save(); // not charged to the grant

    expect($grant->spent()->toMinor())->toBe(300_000)
        ->and($grant->committed()->toMinor())->toBe(200_000)
        ->and($grant->remaining()->toMinor())->toBe(500_000);

    $this->actingAs($this->finance);
    Livewire::test(ViewGrant::class, ['record' => $grant->getRouteKey()])
        ->assertOk()
        ->assertSee('GH₵ 3,000.00')
        ->assertSee('GH₵ 5,000.00')
        ->assertSee('Bright Futures Trust');
});

it('runs the pipeline from the page for those who manage grants, and only reads for those who do not', function () {
    $grant = grant();

    $this->actingAs($this->finance);
    $page = Livewire::test(ViewGrant::class, ['record' => $grant->getRouteKey()])
        ->assertActionVisible('submit')
        ->assertActionHidden('award')
        ->callAction('submit');

    expect($grant->fresh()->status)->toBe(Grant::STATUS_SUBMITTED);

    $page->callAction('award', ['amount' => '45000.00']);
    expect($grant->fresh()->status)->toBe(Grant::STATUS_AWARDED)->and($grant->fresh()->amount_awarded->toMinor())->toBe(4_500_000);

    $officer = User::factory()->staff()->withTwoFactor()->create();
    $officer->assignRole('Programme Officer');
    $this->actingAs($officer->fresh());

    Livewire::test(ViewGrant::class, ['record' => $grant->getRouteKey()])
        ->assertOk()
        ->assertActionHidden('close')
        ->assertActionHidden('edit');

    $this->get(route('filament.admin.resources.grants.create'))->assertForbidden();
});

// ── Obligations and reminders ───────────────────────────────────────────────

it('reminds the owner of an obligation due within a fortnight, once a week, until it is done', function () {
    $grant = grant();
    $grant->award(Money::ofMinor(1_000_000));

    $soon = $grant->obligations()->create(['title' => 'Six-month narrative report', 'kind' => 'report', 'due_on' => now()->addDays(10)->toDateString()]);
    $grant->obligations()->create(['title' => 'Final audit', 'kind' => 'audit', 'due_on' => now()->addMonths(6)->toDateString()]);
    $done = $grant->obligations()->create(['title' => 'Tranche 1 receipt', 'kind' => 'receipt', 'due_on' => now()->addDays(3)->toDateString()]);
    $done->complete($this->finance);

    $this->artisan('scghf:grant-reminders', ['--execute' => true])->assertSuccessful();

    $queued = ScheduledMessage::query()->where('template_key', 'grants.obligation_due')->get();

    expect($queued)->toHaveCount(1)
        ->and($queued->first()->to_address)->toBe('finance@foundation.test')
        ->and($soon->fresh()->reminded_at)->not->toBeNull();

    // Tomorrow: nothing new (reminded this week).
    $this->travel(1)->days();
    $this->artisan('scghf:grant-reminders', ['--execute' => true])->assertSuccessful();
    expect(ScheduledMessage::query()->where('template_key', 'grants.obligation_due')->count())->toBe(1);

    // Eight days on, still not done: reminded again, and it is now overdue.
    $this->travel(8)->days();
    $this->artisan('scghf:grant-reminders', ['--execute' => true])->assertSuccessful();
    expect(ScheduledMessage::query()->where('template_key', 'grants.obligation_due')->count())->toBe(2)
        ->and($soon->fresh()->isOverdue())->toBeFalse(); // due in 1 day still

    $this->travel(3)->days();
    expect($soon->fresh()->isOverdue())->toBeTrue();
});

it('records an obligation as done from the grant page', function () {
    $grant = grant();
    $obligation = $grant->obligations()->create(['title' => 'Report', 'kind' => 'report', 'due_on' => now()->addDays(5)->toDateString()]);

    $this->actingAs($this->finance);

    Livewire::test(ObligationsRelationManager::class, ['ownerRecord' => $grant, 'pageClass' => ViewGrant::class])
        ->assertOk()
        ->callAction(TestAction::make('complete')->table($obligation));

    expect($obligation->fresh()->completed_on)->not->toBeNull()->and($obligation->fresh()->completed_by)->toBe($this->finance->id);
});

// ── Documents ───────────────────────────────────────────────────────────────

it('keeps a grant document on the private disk and serves it on a signed link to staff who may see grants', function () {
    $grant = grant();
    $file = UploadedFile::fake()->create('agreement.pdf', 20, 'application/pdf');
    file_put_contents($file->getRealPath(), "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n");

    $document = app(GrantDocuments::class)->attach($grant, $file, 'agreement', 'Grant agreement', $this->finance);
    $link = app(GrantDocuments::class)->link($document);

    expect($document->media->disk)->toBe('downloads');

    $this->actingAs($this->finance)->get($link)->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->actingAs($this->finance)->get(route('grants.documents.download', $document))->assertForbidden();

    $support = User::factory()->staff()->withTwoFactor()->create();
    $support->assignRole('Support');
    $this->actingAs($support->fresh())->get($link)->assertForbidden();
});

// ── Payouts: the screen the ledger never had ────────────────────────────────

it('raises a payout as a draft, submits it, and refuses the requester as approver', function () {
    $division = Division::factory()->create();
    $grant = grant();
    $grant->award(Money::ofMinor(1_000_000));

    $this->actingAs($this->finance);

    Livewire::test(CreatePayout::class)
        ->fillForm([
            'payee_name' => 'Bongo D/A Primary',
            'amount' => '1500.00',
            'category' => Payout::CATEGORY_SCHOOL_FEES,
            'method' => 'bank_transfer',
            'purpose' => 'School fees, term 2, 40 children',
            'division_id' => $division->id,
            'grant_id' => $grant->id,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $payout = Payout::query()->where('payee_name', 'Bongo D/A Primary')->firstOrFail();

    expect($payout->status)->toBe(Payout::STATUS_DRAFT)
        ->and($payout->amount->toMinor())->toBe(150_000)
        ->and($payout->grant_id)->toBe($grant->id);

    Livewire::test(ViewPayout::class, ['record' => $payout->getRouteKey()])
        ->assertActionVisible('submit')
        ->callAction('submit');

    expect($payout->fresh()->status)->toBe(Payout::STATUS_PENDING)->and($payout->fresh()->requested_by)->toBe($this->finance->id);

    // Finance holds no `payouts.approve`; the Admin does, but the requester never may.
    Livewire::test(ViewPayout::class, ['record' => $payout->getRouteKey()])->assertActionHidden('approve');

    $this->actingAs($this->admin);
    Livewire::test(ViewPayout::class, ['record' => $payout->getRouteKey()])
        ->assertActionVisible('approve')
        ->callAction('approve');

    expect($payout->fresh()->status)->toBe(Payout::STATUS_APPROVED);

    // Marking paid needs evidence; without a file the model refuses.
    $this->actingAs($this->finance);
    Livewire::test(ViewPayout::class, ['record' => $payout->getRouteKey()])
        ->assertActionVisible('paid')
        ->assertActionDoesNotExist('delete');
});

it('refuses a payout attributed to nothing, in the model’s words', function () {
    $this->actingAs($this->finance);

    Livewire::test(CreatePayout::class)
        ->fillForm(['payee_name' => 'Somebody', 'amount' => '10.00', 'category' => 'other', 'method' => 'cash', 'purpose' => 'x'])
        ->call('create')
        ->assertNotified();

    expect(Payout::count())->toBe(0);
});
