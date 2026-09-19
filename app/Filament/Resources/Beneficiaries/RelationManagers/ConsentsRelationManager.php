<?php

declare(strict_types=1);

namespace App\Filament\Resources\Beneficiaries\RelationManagers;

use App\Beneficiaries\CaseAccess;
use App\Models\Beneficiary;
use App\Models\BeneficiaryNote;
use App\Models\Consent;
use App\Support\AuditLogger;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * What this person (or their guardian) has agreed to, on paper.
 *
 * The data-processing consent is recorded at intake and is what lets the
 * case exist. Everything else — a photograph, their story, their name in
 * print — is separate, optional, and DEFAULTS TO NOT GIVEN: no row, no
 * publication, and the media gate already enforces that. Revoking a photo
 * consent unpublishes the pictures on the next request.
 *
 * The same rules the media library's consent manager applies (a child
 * needs a named guardian; a child cannot consent for themselves) are the
 * model's, so they hold here too.
 */
class ConsentsRelationManager extends RelationManager
{
    protected static string $relationship = 'consents';

    protected static ?string $title = 'Consent';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = auth()->user();

        return $user !== null && $ownerRecord instanceof Beneficiary
            && $user->can('consents.view')
            && in_array('B', app(CaseAccess::class)->views($user, $ownerRecord), true);
    }

    protected function getCreateAuthorizationResponse(): Response
    {
        $user = auth()->user();

        return $user !== null && app(CaseAccess::class)->canRecordConsent($user, $this->case()) ? Response::allow() : Response::deny();
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    private function case(): Beneficiary
    {
        $owner = $this->getOwnerRecord();

        if (! $owner instanceof Beneficiary) {
            throw new \LogicException('This manager belongs to a beneficiary case.');
        }

        return $owner;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('consent_type')->label(__('Consent for'))->options([
                Consent::TYPE_PHOTO => __('Photographs'),
                Consent::TYPE_VIDEO => __('Video'),
                Consent::TYPE_STORY => __('Their story'),
                Consent::TYPE_NAME_USE => __('Their name'),
                Consent::TYPE_DATA_PROCESSING => __('Holding this record'),
            ])->required(),
            Select::make('scope')->label(__('Where it may be used'))->options([
                Consent::SCOPE_WEBSITE => __('The website'),
                Consent::SCOPE_SOCIAL => __('Social media'),
                Consent::SCOPE_PRINT => __('Print'),
                Consent::SCOPE_ALL => __('Anywhere'),
            ])->default(Consent::SCOPE_WEBSITE)->required(),
            Toggle::make('is_minor')->label(__('The person is a child'))->live(),
            TextInput::make('granted_by_name')->label(__('Who signed'))->required()->maxLength(191),
            Select::make('granted_by_relationship')->label(__('Their relationship to the person'))->options([
                Consent::BY_SELF => __('It is the person themselves'),
                Consent::BY_PARENT => __('Parent'),
                Consent::BY_GUARDIAN => __('Guardian'),
                Consent::BY_NEXT_OF_KIN => __('Next of kin'),
            ])->default(Consent::BY_SELF)->required(),
            TextInput::make('guardian_name')->label(__('The child’s parent or guardian'))->maxLength(191)
                ->visible(fn (Get $get): bool => (bool) $get('is_minor'))
                ->required(fn (Get $get): bool => (bool) $get('is_minor')),
            DatePicker::make('granted_at')->label(__('Given on'))->default(now())->required()->maxDate(now()),
            DatePicker::make('expires_at')->label(__('Until'))->helperText(__('Leave empty for no end date. For a child, a date is kinder — they grow up.')),
            Textarea::make('notes')->label(__('Notes'))->rows(2)->helperText(__('Attach the signed form under Documents.')),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('consent_type')->label(__('For'))->badge(),
                TextColumn::make('scope')->label(__('Where')),
                TextColumn::make('granted_by_name')->label(__('Given by'))
                    ->description(fn (Consent $record): string => $record->is_minor ? __('for a child; guardian :name', ['name' => $record->guardian_name]) : (string) $record->granted_by_relationship),
                TextColumn::make('granted_at')->label(__('On'))->date('j M Y'),
                TextColumn::make('expires_at')->label(__('Until'))->date('j M Y')->placeholder(__('No end')),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->state(fn (Consent $record): string => match (true) {
                        $record->revoked_at !== null => __('Revoked'),
                        ! $record->isValid() => __('Expired'),
                        default => __('Valid'),
                    })
                    ->color(fn (Consent $record): string => $record->isValid() ? 'success' : 'danger'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label(__('Record consent'))
                    ->mutateDataUsing(function (array $data): array {
                        $data['recorded_by'] = auth()->id();
                        $data['captured_ip'] = request()->ip();

                        return $data;
                    })
                    ->after(function (Consent $record): void {
                        $case = $this->case();
                        app(AuditLogger::class)->record('consent.recorded', sprintf('Recorded %s consent on case %s, given by %s.', $record->consent_type, $case->case_reference, $record->granted_by_name), $case);
                        $case->note(__(':type consent recorded, given by :name.', ['type' => ucfirst($record->consent_type), 'name' => $record->granted_by_name]), BeneficiaryNote::KIND_CONSENT);
                    }),
            ])
            ->recordActions([
                Action::make('revoke')
                    ->label(__('Revoke'))
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Consent $record): bool => $record->revoked_at === null && app(CaseAccess::class)->canRecordConsent(auth()->user(), $this->case()))
                    ->schema([
                        Textarea::make('reason')->label(__('Why'))->required()->rows(3),
                    ])
                    ->action(function (Consent $record, array $data): void {
                        try {
                            $record->revoke((string) $data['reason'], auth()->user());
                        } catch (RuntimeException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return;
                        }

                        $case = $this->case();
                        app(AuditLogger::class)->record('consent.revoked', sprintf('Revoked %s consent on case %s: %s', $record->consent_type, $case->case_reference, $data['reason']), $case);
                        $case->note(__(':type consent revoked: :reason', ['type' => ucfirst($record->consent_type), 'reason' => $data['reason']]), BeneficiaryNote::KIND_CONSENT);
                        Notification::make()->title(__('Consent revoked'))->success()->send();
                    }),
            ])
            ->toolbarActions([]);
    }
}
