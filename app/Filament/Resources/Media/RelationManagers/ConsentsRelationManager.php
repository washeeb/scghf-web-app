<?php

declare(strict_types=1);

namespace App\Filament\Resources\Media\RelationManagers;

use App\Models\Consent;
use App\Models\Media;
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
use RuntimeException;

/**
 * Who agreed to this photograph being used, and on what terms.
 *
 * The model refuses a consent for a child without a named guardian, or
 * one a child gave for themselves — the form asks for exactly what the
 * model will accept. The signed form is attached as evidence: a consent
 * nobody can produce is not one anybody can rely on. Revoking is an
 * action with a reason, and the image stops appearing on the next request.
 */
class ConsentsRelationManager extends RelationManager
{
    protected static string $relationship = 'consents';

    protected static ?string $title = 'Consent';

    /** `consents.manage` records and revokes; `consents.view` reads. Both seeded in Phase 3. */
    protected function getCreateAuthorizationResponse(): Response
    {
        return auth()->user()->can('consents.manage') ? Response::allow() : Response::deny();
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('consent_type')->label(__('Consent for'))->options([
                Consent::TYPE_PHOTO => __('This photograph'),
                Consent::TYPE_VIDEO => __('Video'),
                Consent::TYPE_STORY => __('Their story'),
            ])->default(Consent::TYPE_PHOTO)->required(),
            Select::make('scope')->label(__('Where it may be used'))->options([
                Consent::SCOPE_WEBSITE => __('The website'),
                Consent::SCOPE_SOCIAL => __('Social media'),
                Consent::SCOPE_PRINT => __('Print'),
                Consent::SCOPE_ALL => __('Anywhere'),
            ])->default(Consent::SCOPE_WEBSITE)->required(),
            Toggle::make('is_minor')->label(__('The person is a child'))->live()
                ->default(fn (): bool => (bool) $this->getOwnerRecord()->depicts_children),
            TextInput::make('granted_by_name')->label(__('Who gave the consent'))->required()->maxLength(191),
            Select::make('granted_by_relationship')->label(__('Their relationship to the person'))->options([
                Consent::BY_SELF => __('It is the person themselves'),
                Consent::BY_PARENT => __('Parent'),
                Consent::BY_GUARDIAN => __('Guardian'),
                'next_of_kin' => __('Next of kin'),
            ])->default(fn (): string => $this->getOwnerRecord()->depicts_children ? Consent::BY_PARENT : Consent::BY_SELF)->required(),
            TextInput::make('guardian_name')->label(__('The child’s parent or guardian'))->maxLength(191)
                ->visible(fn (Get $get): bool => (bool) $get('is_minor'))
                ->required(fn (Get $get): bool => (bool) $get('is_minor')),
            DatePicker::make('granted_at')->label(__('Given on'))->default(now())->required()->maxDate(now()),
            DatePicker::make('expires_at')->label(__('Until'))->helperText(__('Leave empty for no end date. For a child, a date is kinder — they grow up.')),
            Select::make('evidence_media_id')->label(__('The signed form'))
                ->options(fn (): array => Media::query()->where('mime_type', 'application/pdf')->orWhere('mime_type', 'like', 'image/%')->orderByDesc('id')->limit(100)->pluck('name', 'id')->all())
                ->searchable()->nullable()
                ->helperText(__('A scan or photo of the signed consent form, uploaded to the media library. Strongly recommended.')),
            Textarea::make('notes')->label(__('Notes'))->rows(2),
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
                TextColumn::make('evidence_media_id')->label(__('Form'))->formatStateUsing(fn (): string => __('Attached'))->placeholder(__('None'))->badge()->color('gray'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label(__('Record consent'))
                    ->mutateDataUsing(function (array $data): array {
                        $data['recorded_by'] = auth()->id();
                        $data['captured_ip'] = request()->ip();

                        return $data;
                    })
                    ->after(fn (Consent $record) => app(AuditLogger::class)->record('consent.recorded', sprintf('Recorded %s consent for media #%d, given by %s.', $record->consent_type, $this->getOwnerRecord()->getKey(), $record->granted_by_name), $this->getOwnerRecord())),
            ])
            ->recordActions([
                Action::make('revoke')
                    ->label(__('Revoke'))
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Consent $record): bool => $record->revoked_at === null && auth()->user()->can('consents.manage'))
                    ->schema([
                        Textarea::make('reason')->label(__('Why'))->required()->rows(3)->helperText(__('"The mother rang and asked" is enough. The image stops appearing on the site at once.')),
                    ])
                    ->action(function (Consent $record, array $data): void {
                        try {
                            $record->revoke((string) $data['reason'], auth()->user());
                        } catch (RuntimeException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return;
                        }

                        app(AuditLogger::class)->record('consent.revoked', sprintf('Revoked consent #%d on media #%d: %s', $record->getKey(), $this->getOwnerRecord()->getKey(), $data['reason']), $this->getOwnerRecord());
                        Notification::make()->title(__('Revoked. The image no longer appears on the site.'))->warning()->send();
                    }),
            ]);
    }
}
