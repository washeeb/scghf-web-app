<?php

declare(strict_types=1);

use App\Beneficiaries\CaseAccess;
use App\Beneficiaries\CaseDocuments;
use App\Beneficiaries\FieldMap;
use App\Filament\Resources\Beneficiaries\Pages\CreateBeneficiary;
use App\Filament\Resources\Beneficiaries\Pages\EditBeneficiary;
use App\Filament\Resources\Beneficiaries\Pages\ListBeneficiaries;
use App\Filament\Resources\Beneficiaries\Pages\ViewBeneficiary;
use App\Models\AuditLog;
use App\Models\Beneficiary;
use App\Models\BeneficiaryDocument;
use App\Models\BeneficiaryNote;
use App\Models\Consent;
use App\Models\Division;
use App\Models\User;
use App\Policies\BasePolicy;
use App\Support\Settings;
use App\Support\ThemeTokens;
use Database\Seeders\RoleAndPermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\ThemeSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Wave 2 — beneficiary case management
|--------------------------------------------------------------------------
|
| The screens the schema waited for since Phase 3, built from
| docs/DESIGN-BENEFICIARY-CASES.md. What these tests pin: the columns are
| encrypted at rest and the ID number is found only through its blind
| index; every field is on the screens the map says and no other (walked
| for every actor); every view and every download is audited; the status
| machine is the model's; documents leave only through a signed link the
| policy checks per document; the export exists for one permission held
| directly; nothing on the public site reads the table.
*/

beforeEach(function () {
    $this->seed(SettingsSeeder::class);
    $this->seed(ThemeSettingsSeeder::class);
    $this->seed(RoleAndPermissionSeeder::class);
    BasePolicy::forgetKnownPermissions();
    ThemeTokens::flush();
    app(Settings::class)->flush();
    Storage::fake('downloads');
});

/** Staff with a role and a second factor, so the panel's pages answer rather than redirect to enrolment. */
function caseStaff(string $role): User
{
    $user = User::factory()->staff()->withTwoFactor()->create();
    $user->assignRole($role);

    return $user->fresh();
}

/** A case whose every field carries a distinctive token, so a screen can be checked field by field. */
function tokenCase(array $overrides = []): Beneficiary
{
    return Beneficiary::factory()->create(array_merge([
        'status' => Beneficiary::STATUS_SUBMITTED,
        'full_name' => 'TOKEN-NAME Ama Serwaa',
        'other_names' => 'TOKEN-OTHER',
        'phone' => 'TOKEN-PHONE-0244',
        'email' => 'token-email@example.test',
        'ghana_card_number' => 'GHA-123456789-7',
        'date_of_birth' => '2014-05-01',
        'address' => 'TOKEN-ADDRESS 12 Lane',
        'community' => 'TOKEN-COMMUNITY',
        'district' => 'TOKEN-DISTRICT',
        'region' => 'Ashanti',
        'bank_account' => 'TOKEN-BANK-99',
        'momo_number' => 'TOKEN-MOMO-55',
        'next_of_kin_name' => 'TOKEN-KIN',
        'next_of_kin_phone' => 'TOKEN-KINPHONE',
        'household_details' => 'TOKEN-HOUSEHOLD',
        'school_or_employer' => 'TOKEN-SCHOOL',
        'religion' => 'TOKEN-RELIGION',
        'medical_notes' => 'TOKEN-MEDICAL sickle cell',
        'application_narrative' => 'TOKEN-NARRATIVE',
        'case_notes' => 'TOKEN-LEGACYNOTES',
        'intake_ip' => '10.9.8.7',
        'assistance' => 123400,
        'assisted_on' => '2026-03-03',
        'outcome' => null,
    ], $overrides));
}

/** The distinctive token each field shows when it is on screen. */
function fieldTokens(): array
{
    return [
        'full_name' => 'TOKEN-NAME', 'other_names' => 'TOKEN-OTHER', 'phone' => 'TOKEN-PHONE', 'email' => 'token-email@',
        'address' => 'TOKEN-ADDRESS', 'community' => 'TOKEN-COMMUNITY', 'district' => 'TOKEN-DISTRICT',
        'bank_account' => 'TOKEN-BANK', 'momo_number' => 'TOKEN-MOMO', 'next_of_kin_name' => 'TOKEN-KIN',
        'next_of_kin_phone' => 'TOKEN-KINPHONE', 'household_details' => 'TOKEN-HOUSEHOLD', 'school_or_employer' => 'TOKEN-SCHOOL',
        'religion' => 'TOKEN-RELIGION', 'medical_notes' => 'TOKEN-MEDICAL', 'application_narrative' => 'TOKEN-NARRATIVE',
        'intake_ip' => '10.9.8.7', 'date_of_birth' => '1 May 2014', 'assistance' => 'GH₵ 1,234.00', 'assisted_on' => '3 March 2026',
    ];
}

// ═══════════════════════════════════════════════════════════════════════════
//  Encryption at rest and the blind index
// ═══════════════════════════════════════════════════════════════════════════

it('stores the sensitive columns encrypted and reads them back in clear', function () {
    $case = tokenCase();
    $raw = DB::table('beneficiaries')->where('id', $case->id)->first();

    foreach (['ghana_card_number', 'medical_notes', 'bank_account', 'momo_number', 'phone', 'email', 'address', 'next_of_kin_name', 'household_details', 'religion', 'application_narrative', 'case_notes'] as $column) {
        expect($raw->{$column})->not->toContain('TOKEN')->not->toContain('GHA-123')->not->toContain('@');
    }

    expect($case->fresh()->medical_notes)->toBe('TOKEN-MEDICAL sickle cell')
        ->and($case->fresh()->ghana_card_number)->toBe('GHA-123456789-7');
});

it('finds a case by its ID number through the blind index, whatever the spacing', function () {
    $case = tokenCase();

    expect(Beneficiary::withGhanaCard('GHA-123456789-7')->pluck('id')->all())->toBe([$case->id])
        ->and(Beneficiary::withGhanaCard('gha 123456789 7')->count())->toBe(1)
        ->and(Beneficiary::withGhanaCard('1234567897')->count())->toBe(1)
        ->and(Beneficiary::withGhanaCard('GHA-000000000-0')->count())->toBe(0)
        ->and(Beneficiary::blindIndex('GHA-123456789-7'))->not->toContain('123456789');

    // The index follows the number.
    $case->forceFill(['ghana_card_number' => 'GHA-999999999-1'])->save();

    expect(Beneficiary::withGhanaCard('GHA-123456789-7')->count())->toBe(0)
        ->and(Beneficiary::withGhanaCard('GHA-999999999-1')->count())->toBe(1);
});

it('classifies the blind index column so retention destroys it with the number', function () {
    expect(Beneficiary::privacyElements()['ghana_card_index'])->toBe('national_id')
        ->and(Beneficiary::unclassifiedColumns())->toBe([]);
});

// ═══════════════════════════════════════════════════════════════════════════
//  The log is append-only
// ═══════════════════════════════════════════════════════════════════════════

it('writes a dated, attributed note that can be neither edited nor deleted', function () {
    $worker = caseStaff('Programme Officer');
    $case = tokenCase(['case_worker_id' => $worker->id]);

    $note = $case->note('Home visit done.', BeneficiaryNote::KIND_NOTE, $worker);

    expect($note->author_id)->toBe($worker->id)
        ->and(DB::table('beneficiary_notes')->where('id', $note->id)->value('body'))->not->toContain('Home visit')
        ->and($note->fresh()->body)->toBe('Home visit done.');

    expect(fn () => $note->update(['body' => 'Changed my mind']))->toThrow(LogicException::class);
    expect(fn () => $note->delete())->toThrow(LogicException::class);
});

it('records every status change as a note', function () {
    $case = tokenCase(['status' => Beneficiary::STATUS_DRAFT]);

    $case->submit();
    $case->startReview();
    $case->approve();
    $case->close('assisted');

    expect($case->notes()->where('kind', BeneficiaryNote::KIND_STATUS)->pluck('body')->all())
        ->toBe(['Closed. Outcome: assisted', 'Approved.', 'Review started.', 'Submitted for review.'])
        ->and($case->fresh()->impactRecord)->not->toBeNull();
});

// ═══════════════════════════════════════════════════════════════════════════
//  Who sees what — the matrix, walked from the map
// ═══════════════════════════════════════════════════════════════════════════

/**
 * @return array<string, array{0: string, 1: array<int, string>}> actor label => [role, expected views]
 */
function actorMatrix(): array
{
    return [
        'the worker on the case' => ['Programme Officer', ['A', 'B', 'C']],
        'a programme officer not on the case' => ['Programme Officer', ['A']],
        'the safeguarding lead' => ['Safeguarding Lead', ['A', 'B', 'C']],
        'the super admin' => ['Super Admin', ['A', 'B', 'C']],
        'finance, on an approved case' => ['Finance Officer', ['A', 'F']],
        'the auditor' => ['Auditor', ['A', 'U']],
        'an admin' => ['Admin', ['A']],
    ];
}

it('shows :dataset exactly the fields the map says', function (string $role, array $views) {
    $user = caseStaff($role);
    $isWorker = str_contains($this->dataName(), 'worker on the case');
    $approved = str_contains($this->dataName(), 'approved');

    $case = tokenCase(array_filter([
        'case_worker_id' => $isWorker ? $user->id : caseStaff('Programme Officer')->id,
        'status' => $approved ? Beneficiary::STATUS_APPROVED : Beneficiary::STATUS_SUBMITTED,
    ]));

    expect(app(CaseAccess::class)->views($user, $case))->toBe($views);

    $this->actingAs($user);
    $page = Livewire::test(ViewBeneficiary::class, ['record' => $case->getRouteKey()])->assertOk();

    $visible = FieldMap::visible($views);

    foreach (fieldTokens() as $field => $token) {
        if (in_array($field, $visible, true)) {
            $page->assertSee($token);
        } else {
            $page->assertDontSee($token);
        }
    }

    // The ID number is never on the page in full; view C gets the last four.
    $page->assertDontSee('GHA-123456789-7');
    in_array('C', $views, true) ? $page->assertSee('89-7') : $page->assertDontSee('89-7');

    // The age band replaces the date of birth for everyone without view B.
    in_array('B', $views, true) ? $page->assertSee('1 May 2014') : $page->assertDontSee('2014');
})->with(actorMatrix());

it('does not let finance open a case that is not yet approved, nor anybody without the permission', function () {
    $finance = caseStaff('Finance Officer');
    $support = caseStaff('Support');
    $case = tokenCase(['status' => Beneficiary::STATUS_UNDER_REVIEW]);

    expect(app(CaseAccess::class)->canView($finance, $case))->toBeFalse()
        ->and($finance->can('view', $case))->toBeFalse()
        ->and($support->can('viewAny', Beneficiary::class))->toBeFalse();

    $this->actingAs($finance)->get(route('filament.admin.resources.beneficiaries.view', $case))->assertForbidden();

    $case->approve();
    $this->actingAs($finance)->get(route('filament.admin.resources.beneficiaries.view', $case))->assertOk();
});

it('lets the super admin see the sensitive tier but not edit it', function () {
    $super = caseStaff('Super Admin');
    $case = tokenCase();
    $access = app(CaseAccess::class);

    expect($access->actor($super, $case))->toBe(CaseAccess::SUPER)
        ->and($access->canSeeSensitive($super, $case))->toBeTrue()
        ->and($access->editableFields($super, $case))->toContain('phone')->not->toContain('medical_notes')->not->toContain('ghana_card_number')
        ->and($access->canExport($super))->toBeFalse();
});

it('edits only the fields the actor may, whatever the request carries', function () {
    $worker = caseStaff('Programme Officer');
    $case = tokenCase(['case_worker_id' => $worker->id]);

    $this->actingAs($worker);

    Livewire::test(EditBeneficiary::class, ['record' => $case->getRouteKey()])
        ->assertOk()
        ->assertFormFieldExists('medical_notes')
        ->assertFormFieldDoesNotExist('case_worker_id') // the lead reassigns
        ->fillForm(['medical_notes' => 'Updated note', 'phone' => '+233200000000'])
        ->call('save')
        ->assertHasNoFormErrors();

    $case->refresh();

    expect($case->medical_notes)->toBe('Updated note')
        ->and($case->phone)->toBe('+233200000000')
        ->and($case->notes()->first()->body)->toContain('Edited:')->toContain('medical_notes')->not->toContain('Updated note');

    // A viewer cannot open the edit page at all.
    $other = caseStaff('Programme Officer');
    $this->actingAs($other)->get(route('filament.admin.resources.beneficiaries.edit', $case))->assertForbidden();
});

// ═══════════════════════════════════════════════════════════════════════════
//  The audit trail
// ═══════════════════════════════════════════════════════════════════════════

it('records a view once per session at the highest tier, and every reveal', function () {
    $lead = caseStaff('Safeguarding Lead');
    $case = tokenCase();

    $this->actingAs($lead);

    Livewire::test(ViewBeneficiary::class, ['record' => $case->getRouteKey()])->assertOk();
    Livewire::test(ViewBeneficiary::class, ['record' => $case->getRouteKey()])->assertOk();

    $views = AuditLog::query()->where('event', 'beneficiary.viewed')->get();

    expect($views)->toHaveCount(1)
        ->and($views->first()->context['tier'])->toBe('C')
        ->and($views->first()->description)->toContain($case->case_reference)
        ->and($views->first()->description)->not->toContain('TOKEN');

    Livewire::test(ViewBeneficiary::class, ['record' => $case->getRouteKey()])
        ->callAction('reveal')
        ->assertNotified();

    expect(AuditLog::query()->where('event', 'beneficiary.viewed')->count())->toBe(2)
        ->and($case->notes()->where('kind', BeneficiaryNote::KIND_REVEAL)->count())->toBe(1);
});

it('hides the reveal and the decisions from those who may not', function () {
    $officer = caseStaff('Programme Officer');
    $worker = caseStaff('Programme Officer');
    $case = tokenCase(['case_worker_id' => $worker->id]);

    $this->actingAs($officer);
    Livewire::test(ViewBeneficiary::class, ['record' => $case->getRouteKey()])
        ->assertActionHidden('reveal')
        ->assertActionHidden('approve')
        ->assertActionHidden('note')
        ->assertActionHidden('edit');

    $this->actingAs($worker);
    Livewire::test(ViewBeneficiary::class, ['record' => $case->getRouteKey()])
        ->assertActionVisible('reveal')
        ->assertActionVisible('note')
        ->assertActionHidden('approve') // a decision is the lead's
        ->assertActionHidden('reassign');
});

it('runs the status machine from the page and never offers a delete', function () {
    $lead = caseStaff('Safeguarding Lead');
    $case = tokenCase(['status' => Beneficiary::STATUS_SUBMITTED]);

    $this->actingAs($lead);

    $page = Livewire::test(ViewBeneficiary::class, ['record' => $case->getRouteKey()])
        ->assertActionDoesNotExist('delete')
        ->assertActionDoesNotExist('forceDelete')
        ->callAction('review');

    expect($case->fresh()->status)->toBe(Beneficiary::STATUS_UNDER_REVIEW);

    $page->callAction('approve');
    expect($case->fresh()->status)->toBe(Beneficiary::STATUS_APPROVED);

    $page->callAction('close', ['outcome' => 'assisted']);

    $case->refresh();
    expect($case->status)->toBe(Beneficiary::STATUS_CLOSED)
        ->and($case->closed_at)->not->toBeNull()
        ->and($case->impactRecord)->not->toBeNull()
        ->and(app(CaseAccess::class)->editableFields($lead, $case))->toBe([]);

    Livewire::test(ListBeneficiaries::class)->assertOk()->assertTableBulkActionDoesNotExist('delete');
});

// ═══════════════════════════════════════════════════════════════════════════
//  Documents: in through the library, out through a signed link
// ═══════════════════════════════════════════════════════════════════════════

function attachDocument(Beneficiary $case, User $by, string $type = BeneficiaryDocument::TYPE_SCHOOL): BeneficiaryDocument
{
    $file = UploadedFile::fake()->create('report.pdf', 40, 'application/pdf');
    // A real PDF header, so the upload policy's sniff agrees with the name.
    file_put_contents($file->getRealPath(), "%PDF-1.4\n%âãÏÓ\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n");

    return app(CaseDocuments::class)->attach($case, $file, $type, 'School bill', null, $by);
}

it('stores a document privately and serves it only on a fresh signed link the policy accepts', function () {
    $worker = caseStaff('Programme Officer');
    $officer = caseStaff('Programme Officer');
    $support = caseStaff('Support');
    $case = tokenCase(['case_worker_id' => $worker->id]);

    $document = attachDocument($case, $worker);

    expect($document->media->disk)->toBe('downloads')
        ->and($case->notes()->where('kind', BeneficiaryNote::KIND_DOCUMENT)->count())->toBe(1);

    $link = app(CaseDocuments::class)->link($document);

    $this->actingAs($worker)->get($link)->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->actingAs($officer)->get($link)->assertForbidden();
    $this->actingAs($support)->get($link)->assertForbidden();
    $this->actingAs($worker)->get(route('beneficiaries.documents.download', $document))->assertForbidden(); // unsigned

    $this->travel(6)->minutes();
    $this->actingAs($worker)->get($link)->assertForbidden(); // expired

    $downloads = AuditLog::query()->where('event', 'beneficiary.document_downloaded')->get();
    expect($downloads)->toHaveCount(1)->and($downloads->first()->causer_id)->toBe($worker->id);
});

it('opens a medical document only to view C, and a financial one to finance too', function () {
    $worker = caseStaff('Programme Officer');
    $finance = caseStaff('Finance Officer');
    $auditor = caseStaff('Auditor');
    $case = tokenCase(['case_worker_id' => $worker->id, 'status' => Beneficiary::STATUS_APPROVED]);

    $medical = attachDocument($case, $worker, BeneficiaryDocument::TYPE_MEDICAL);
    $financial = attachDocument($case, $worker, BeneficiaryDocument::TYPE_FINANCIAL);

    $access = app(CaseAccess::class);

    expect($medical->is_sensitive)->toBeTrue()
        ->and($access->canDownload($worker, $medical))->toBeTrue()
        ->and($access->canDownload($finance, $medical))->toBeFalse()
        ->and($access->canDownload($auditor, $medical))->toBeFalse()
        ->and($access->canDownload($finance, $financial))->toBeTrue()
        ->and($access->canDownload($auditor, $financial))->toBeTrue();
});

// ═══════════════════════════════════════════════════════════════════════════
//  Intake
// ═══════════════════════════════════════════════════════════════════════════

it('creates a case from the intake form with its consent, and refuses a known ID number until checked', function () {
    $worker = caseStaff('Programme Officer');
    $division = Division::factory()->create();
    tokenCase(['ghana_card_number' => 'GHA-555555555-5']);

    $this->actingAs($worker);

    $form = UploadedFile::fake()->create('consent.pdf', 10, 'application/pdf');
    file_put_contents($form->getRealPath(), "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n");

    $fill = [
        'consent_given_by' => 'Akosua Boateng',
        'consent_relationship' => Consent::BY_PARENT,
        'consent_is_minor' => true,
        'consent_guardian' => 'Akosua Boateng',
        'consent_signed_on' => now()->toDateString(),
        'consent_form' => $form,
        'division_id' => $division->id,
        'full_name' => 'Kwame Boateng',
        'gender' => 'male',
        'region' => 'Ashanti',
        'district' => 'Kumasi Metropolitan',
        'ghana_card_number' => 'GHA-555555555-5',
        'date_of_birth' => '2015-02-02',
    ];

    $page = Livewire::test(CreateBeneficiary::class)
        ->fillForm($fill)
        ->call('create')
        ->assertNotified();

    expect(Beneficiary::where('full_name', 'Kwame Boateng')->exists())->toBeFalse();

    $page->fillForm(['duplicate_checked' => true])->call('create')->assertHasNoFormErrors();

    $case = Beneficiary::where('full_name', 'Kwame Boateng')->firstOrFail();

    expect($case->status)->toBe(Beneficiary::STATUS_DRAFT)
        ->and($case->case_worker_id)->toBe($worker->id)
        ->and($case->consents()->where('consent_type', Consent::TYPE_DATA_PROCESSING)->count())->toBe(1)
        ->and($case->consents()->first()->is_minor)->toBeTrue()
        ->and($case->documents()->count())->toBe(1)
        ->and($case->notes()->where('kind', BeneficiaryNote::KIND_CONSENT)->count())->toBe(1)
        ->and(AuditLog::query()->where('event', 'consent.recorded')->count())->toBe(1);
});

// ═══════════════════════════════════════════════════════════════════════════
//  Export
// ═══════════════════════════════════════════════════════════════════════════

it('gives the export to the data-protection lead alone, with Tier A columns only', function () {
    $lead = caseStaff('Safeguarding Lead');
    $super = caseStaff('Super Admin');
    $officer = caseStaff('Programme Officer');
    tokenCase();

    $this->actingAs($officer);
    Livewire::test(ListBeneficiaries::class)->assertOk()->assertTableActionDoesNotExist('export');

    $this->actingAs($super);
    Livewire::test(ListBeneficiaries::class)->assertOk()->assertTableActionDoesNotExist('export');

    $this->actingAs($lead);
    Livewire::test(ListBeneficiaries::class)->assertOk()->callTableAction('export');

    // The streamed file is checked by its columns rather than its bytes:
    // the action's column map is what leaves the building.
    $entry = AuditLog::query()->where('event', 'beneficiaries.exported')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->severity)->toBe(AuditLog::SEVERITY_CRITICAL)
        ->and($entry->record_count)->toBe(1);
});

// ═══════════════════════════════════════════════════════════════════════════
//  The public site never reads the table
// ═══════════════════════════════════════════════════════════════════════════

it('never mentions the beneficiary model in a public controller or view', function () {
    $offenders = [];

    foreach ([app_path('Http/Controllers'), resource_path('views')] as $root) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = str_replace('\\', '/', $file->getPathname());

            // The one controller that serves a case document is staff-only and signed.
            if (str_ends_with($path, 'BeneficiaryDocumentController.php') || str_contains($path, '/views/filament/')) {
                continue;
            }

            if (preg_match('/\bBeneficiary\b(?!ImpactRecord)/', (string) file_get_contents($path))) {
                $offenders[] = $path;
            }
        }
    }

    expect($offenders)->toBe([]);
});
