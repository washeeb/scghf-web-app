<?php

declare(strict_types=1);

namespace App\Filament\Resources\Beneficiaries\Pages;

use App\Beneficiaries\CaseDocuments;
use App\Filament\Resources\Beneficiaries\BeneficiaryResource;
use App\Models\Beneficiary;
use App\Models\BeneficiaryDocument;
use App\Models\BeneficiaryNote;
use App\Models\Consent;
use App\Support\AuditLogger;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Intake — a member of staff sitting with the applicant (§5.1).
 *
 * ── Consent first, and the form does not save without it ────────────────────
 *
 * The data-processing consent (Act 843 s.20) is captured at the top of the
 * form as the applicant's or guardian's signed paper, uploaded. It becomes
 * a `Consent` row and the scan becomes the case's first document. A case
 * with no consent is a case that cannot be saved — not a warning, a
 * requirement.
 *
 * ── "Has this person applied before?" ───────────────────────────────────────
 *
 * Answered by the blind index, never by the number. A match names the
 * earlier case references and stops the save until the person creating
 * this one confirms they have looked — a duplicate case is how one
 * family is helped twice while another waits.
 *
 * The creator becomes the case worker: it is their case until the
 * safeguarding lead moves it.
 */
class CreateBeneficiary extends CreateRecord
{
    protected static string $resource = BeneficiaryResource::class;

    public function form(Schema $schema): Schema
    {
        $schema = parent::form($schema);

        return $schema->components([
            Section::make(__('Consent to hold this record'))
                ->description(__('Before anything else. The applicant — or, for a child, a parent or guardian — signs the consent form; the signed form is uploaded here. Nothing saves without it.'))
                ->columns(2)
                ->schema([
                    TextInput::make('consent_given_by')->label(__('Signed by'))->required()->maxLength(191),
                    Select::make('consent_relationship')->label(__('Their relationship to the applicant'))->options([
                        Consent::BY_SELF => __('It is the applicant'),
                        Consent::BY_PARENT => __('Parent'),
                        Consent::BY_GUARDIAN => __('Guardian'),
                        Consent::BY_NEXT_OF_KIN => __('Next of kin'),
                    ])->default(Consent::BY_SELF)->required()->live(),
                    Toggle::make('consent_is_minor')->label(__('The applicant is a child'))->live(),
                    TextInput::make('consent_guardian')->label(__('Parent or guardian'))->maxLength(191)
                        ->visible(fn (Get $get): bool => (bool) $get('consent_is_minor'))
                        ->required(fn (Get $get): bool => (bool) $get('consent_is_minor')),
                    DatePicker::make('consent_signed_on')->label(__('Signed on'))->default(now())->maxDate(now())->required(),
                    FileUpload::make('consent_form')
                        ->label(__('The signed form'))
                        ->disk('downloads')
                        ->directory('incoming')
                        ->visibility('private')
                        ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp'])
                        ->maxSize(10240)
                        ->required()
                        ->dehydrated(false)
                        ->helperText(__('A scan or a photograph of the signed page. It is kept with the case, on a private disk.')),
                ]),

            ...$schema->getComponents(),

            Section::make(__('Before saving'))->schema([
                Checkbox::make('duplicate_checked')
                    ->label(__('I have checked for an earlier case for this person'))
                    ->helperText(__('If the ID number matches an earlier case, the save is refused until this is ticked and the earlier case has been looked at.'))
                    ->dehydrated(false),
            ]),
        ]);
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['status'] = Beneficiary::STATUS_DRAFT;
        $data['case_worker_id'] = auth()->id();
        $data['intake_ip'] = request()->ip();

        unset($data['consent_given_by'], $data['consent_relationship'], $data['consent_is_minor'], $data['consent_guardian'], $data['consent_signed_on']);

        return $data;
    }

    protected function beforeCreate(): void
    {
        $state = $this->form->getRawState();

        // The model refuses this too; refusing here keeps the typed form.
        if ((bool) ($state['consent_is_minor'] ?? false) && ($state['consent_relationship'] ?? null) === Consent::BY_SELF) {
            Notification::make()->title(__('A child cannot consent for themselves'))->body(__('Record the parent or guardian as the person who signed.'))->danger()->send();
            $this->halt();
        }

        $number = (string) ($state['ghana_card_number'] ?? '');

        if ($number === '' || (bool) ($state['duplicate_checked'] ?? false)) {
            return;
        }

        $earlier = Beneficiary::withGhanaCard($number)->pluck('case_reference');

        if ($earlier->isEmpty()) {
            return;
        }

        Notification::make()
            ->title(__('This ID number is already on a case'))
            ->body(__('Earlier case(s): :refs. Open them first; if this is a new application, tick "I have checked" and save again.', ['refs' => $earlier->implode(', ')]))
            ->warning()
            ->persistent()
            ->send();

        $this->halt();
    }

    protected function afterCreate(): void
    {
        /** @var Beneficiary $case */
        $case = $this->getRecord();
        $state = $this->form->getRawState();
        $user = auth()->user();

        // The signed form: the case's first document, and the consent's evidence.
        $path = $state['consent_form'] ?? null;
        $path = is_array($path) ? (reset($path) ?: null) : $path;
        $document = null;

        if (is_string($path) && $path !== '' && Storage::disk('downloads')->exists($path)) {
            $absolute = Storage::disk('downloads')->path($path);
            $upload = new UploadedFile($absolute, basename($path), Storage::disk('downloads')->mimeType($path) ?: null, null, true);

            try {
                $document = app(CaseDocuments::class)->attach($case, $upload, BeneficiaryDocument::TYPE_OTHER, __('Data-processing consent form'), null, $user);
            } catch (RuntimeException $e) {
                Notification::make()->title(__('The consent form was not accepted'))->body($e->getMessage())->danger()->persistent()->send();
            }

            Storage::disk('downloads')->delete($path);
        }

        $consent = $case->consents()->create([
            'consent_type' => Consent::TYPE_DATA_PROCESSING,
            'scope' => Consent::SCOPE_ALL,
            'granted_by_name' => (string) $state['consent_given_by'],
            'granted_by_relationship' => (string) $state['consent_relationship'],
            'is_minor' => (bool) ($state['consent_is_minor'] ?? false),
            'guardian_name' => $state['consent_guardian'] ?? null,
            'granted_at' => $state['consent_signed_on'] ?? now(),
            'evidence_media_id' => $document?->media_id,
            'captured_ip' => request()->ip(),
            'recorded_by' => $user?->getKey(),
        ]);

        $case->note(__('Data-processing consent recorded, signed by :name (:rel).', ['name' => $consent->granted_by_name, 'rel' => $consent->granted_by_relationship]), BeneficiaryNote::KIND_CONSENT, $user);

        app(AuditLogger::class)->record('consent.recorded', sprintf('Recorded data-processing consent for case %s.', $case->case_reference), $case, $user);
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
