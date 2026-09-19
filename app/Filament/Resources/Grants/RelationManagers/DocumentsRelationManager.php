<?php

declare(strict_types=1);

namespace App\Filament\Resources\Grants\RelationManagers;

use App\Grants\GrantDocuments;
use App\Models\Grant;
use App\Models\GrantDocument;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

/**
 * The proposal, the agreement, the reports. Private-disk files opened on
 * a day-long signed link.
 */
class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

    protected static ?string $title = 'Documents';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['media', 'grant']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('title')->label(__('Document')),
                TextColumn::make('kind')->label(__('Kind'))->badge()->formatStateUsing(fn (?string $state): string => GrantDocument::KINDS[$state] ?? (string) $state),
                TextColumn::make('media.file_name')->label(__('File'))->fontFamily('mono')->size('xs'),
                TextColumn::make('created_at')->label(__('Added'))->date('j M Y'),
            ])
            ->headerActions([
                Action::make('upload')
                    ->label(__('Add a document'))
                    ->icon('heroicon-o-arrow-up-tray')
                    ->visible(fn (): bool => auth()->user()?->can('grants.manage') ?? false)
                    ->schema([
                        TextInput::make('title')->label(__('What it is'))->required()->maxLength(191),
                        Select::make('kind')->label(__('Kind'))->options(GrantDocument::KINDS)->default('other')->required(),
                        FileUpload::make('file')->label(__('File'))->storeFiles(false)->maxSize(20480)->required()
                            ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']),
                    ])
                    ->action(function (array $data): void {
                        $file = $data['file'] ?? null;

                        if (! $file instanceof TemporaryUploadedFile) {
                            Notification::make()->title(__('No file was received'))->danger()->send();

                            return;
                        }

                        try {
                            app(GrantDocuments::class)->attach($this->grant(), $file, (string) $data['kind'], (string) $data['title'], auth()->user());
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
                    ->url(fn (GrantDocument $record): string => app(GrantDocuments::class)->link($record))
                    ->openUrlInNewTab(),
            ])
            ->toolbarActions([]);
    }

    private function grant(): Grant
    {
        $owner = $this->getOwnerRecord();

        if (! $owner instanceof Grant) {
            throw new \LogicException('This manager belongs to a grant.');
        }

        return $owner;
    }
}
