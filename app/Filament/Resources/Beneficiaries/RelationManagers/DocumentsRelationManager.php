<?php

declare(strict_types=1);

namespace App\Filament\Resources\Beneficiaries\RelationManagers;

use App\Beneficiaries\CaseAccess;
use App\Beneficiaries\CaseDocuments;
use App\Models\Beneficiary;
use App\Models\BeneficiaryDocument;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

/**
 * The files on a case, and the two things that can happen to one: it is
 * added, or it is opened. Never edited, never deleted here — a sensitive
 * document is removed by the retention runner twenty-four months after
 * the case closes, and the table says so on each row.
 *
 * Opening is a signed five-minute link through the controller that checks
 * the policy per document and records the download. The list itself shows
 * every document's existence to anybody on views B, F or U; whether a row
 * has an "Open" depends on the document (a medical report needs view C).
 */
class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

    protected static ?string $title = 'Documents';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = auth()->user();

        if ($user === null || ! $ownerRecord instanceof Beneficiary) {
            return false;
        }

        $views = app(CaseAccess::class)->views($user, $ownerRecord);

        return array_intersect(['B', 'F', 'U'], $views) !== [];
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

    public function table(Table $table): Table
    {
        $access = app(CaseAccess::class);

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['media', 'beneficiary']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('title')->label(__('Document'))->description(fn (BeneficiaryDocument $record): ?string => $record->description),
                TextColumn::make('document_type')->label(__('Type'))->badge()->formatStateUsing(fn (?string $state): string => ucfirst((string) $state)),
                IconColumn::make('is_sensitive')->label(__('Sensitive'))->boolean(),
                TextColumn::make('created_at')->label(__('Added'))->date('j M Y'),
                TextColumn::make('retention')->label(__('Kept until'))
                    ->state(fn (BeneficiaryDocument $record): string => $record->closed_at === null
                        ? __('Case open')
                        : $record->closed_at->addMonths((int) config('compliance.retention.classes.'.$record->retentionClass().'.months', 24))->format('M Y')),
            ])
            ->headerActions([
                Action::make('upload')
                    ->label(__('Add a document'))
                    ->icon('heroicon-o-arrow-up-tray')
                    ->visible(fn (): bool => $access->canUpload(auth()->user(), $this->case()))
                    ->schema([
                        TextInput::make('title')->label(__('What it is'))->required()->maxLength(191),
                        Select::make('document_type')->label(__('Type'))->options([
                            BeneficiaryDocument::TYPE_MEDICAL => __('Medical (sensitive)'),
                            BeneficiaryDocument::TYPE_IDENTITY => __('Identity (sensitive)'),
                            BeneficiaryDocument::TYPE_FINANCIAL => __('Financial'),
                            BeneficiaryDocument::TYPE_SCHOOL => __('School'),
                            BeneficiaryDocument::TYPE_REFERRAL => __('Referral'),
                            BeneficiaryDocument::TYPE_OTHER => __('Other'),
                        ])->default(BeneficiaryDocument::TYPE_OTHER)->required(),
                        Textarea::make('description')->label(__('Notes'))->rows(2)->maxLength(500),
                        FileUpload::make('file')
                            ->label(__('File'))
                            ->storeFiles(false)
                            ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp'])
                            ->maxSize(10240)
                            ->required()
                            ->helperText(__('A PDF or a photograph, up to 10 MB. Images have their camera metadata removed.')),
                    ])
                    ->action(function (array $data): void {
                        $file = $data['file'] ?? null;

                        if (! $file instanceof TemporaryUploadedFile) {
                            Notification::make()->title(__('No file was received'))->danger()->send();

                            return;
                        }

                        try {
                            app(CaseDocuments::class)->attach($this->case(), $file, (string) $data['document_type'], (string) $data['title'], $data['description'] ?? null, auth()->user());
                            Notification::make()->title(__('Document added'))->success()->send();
                        } catch (Throwable $e) {
                            Notification::make()->title(__('The file was not accepted'))->body($e->getMessage())->danger()->persistent()->send();
                        }
                    }),
            ])
            ->recordActions([
                Action::make('open')
                    ->label(__('Open'))
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->visible(fn (BeneficiaryDocument $record): bool => $access->canDownload(auth()->user(), $record))
                    ->url(fn (BeneficiaryDocument $record): string => app(CaseDocuments::class)->link($record))
                    ->openUrlInNewTab(),
            ])
            ->toolbarActions([]);
    }
}
