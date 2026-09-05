<?php

declare(strict_types=1);

namespace App\Filament\Resources\Media\Tables;

use App\Filament\Resources\Media\MediaResource;
use App\Media\Exceptions\MediaInUse;
use App\Media\MediaLibrary;
use App\Media\MediaUsage;
use App\Models\Beneficiary;
use App\Models\Media;
use App\Models\MediaFolder;
use App\Rules\AcceptableUpload;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

/**
 * The library list.
 *
 * ── The first column is whether the file can be used ────────────────────────
 *
 * Not the thumbnail, not the name. The job that brings somebody to this screen
 * is "why will this image not go on the page", and the answer is always either
 * missing alt text or unstripped metadata. A library that makes you open forty
 * files to find the blocked one is a library people work around, and the way
 * people work around this one is by not using it.
 *
 * ── Deleting is expected to fail, and says why ──────────────────────────────
 *
 * `Media::deleting()` throws `MediaInUse` when something references the file.
 * That is the guard doing its job rather than an error, so it is caught and
 * shown as a sentence naming where the file is used — and pointing at the
 * replace action, which is what the person almost always actually wants.
 */
class MediaTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([

                ImageColumn::make('thumbnail')
                    ->label('')
                    ->state(fn (Media $record): ?string => str_starts_with((string) $record->mime_type, 'image/')
                        ? $record->conversionUrl('thumb')
                        : null)
                    ->square()
                    ->size(56),

                TextColumn::make('name')
                    ->label(__('Name'))
                    ->searchable()
                    ->sortable()
                    ->description(fn (Media $record): string => (string) $record->file_name)
                    ->wrap(),

                TextColumn::make('publishable')
                    ->label(__('Usable'))
                    ->badge()
                    ->state(fn (Media $record): string => match (true) {
                        $record->isPublishable() => __('Ready'),
                        $record->sanitisation_error !== null => __('Sanitising failed'),
                        ! $record->hasBeenSanitised() => __('Metadata not removed'),
                        default => __('Needs alt text'),
                    })
                    ->color(fn (Media $record): string => match (true) {
                        $record->isPublishable() => 'success',
                        $record->sanitisation_error !== null => 'danger',
                        ! $record->hasBeenSanitised() => 'danger',
                        default => 'warning',
                    }),

                TextColumn::make('folder.name')
                    ->label(__('Folder'))
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('size')
                    ->label(__('Size'))
                    ->formatStateUsing(fn (int $state): string => number_format($state / 1024).' KB')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label(__('Added'))
                    ->date('j M Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([

                SelectFilter::make('folder_id')
                    ->label(__('Folder'))
                    ->options(fn (): array => MediaFolder::orderBy('path')->pluck('name', 'id')->all()),

                Filter::make('blocked')
                    ->label(__('Cannot be published'))
                    /*
                     * The filter this screen exists for. Default OFF, because
                     * somebody arriving to find a photograph should see all of
                     * them — but one click from the list that needs work.
                     */
                    ->query(fn (Builder $query): Builder => $query->whereIn(
                        'id',
                        MediaResource::blockedQuery()->select('id'),
                    )),

                Filter::make('arrived_with_location')
                    ->label(__('Arrived carrying a location'))
                    ->query(fn (Builder $query): Builder => $query->where('had_gps_data', true))
                    ->toggle(),
            ])
            ->recordActions([
                EditAction::make(),
                static::replaceAction(),
                static::deleteAction(),
            ])
            ->emptyStateHeading(__('Nothing here yet'))
            ->emptyStateDescription(__(
                'Upload a photograph or a document. Images have their camera metadata removed on the '
                .'way in, and cannot be put on a page until they have alt text.'
            ));
    }

    /**
     * Uploading.
     *
     * Every file goes through `MediaLibrary::add()` — the only door in, the way
     * `MessageDispatcher` is the only door out. A second path would be a path
     * with no MIME sniffing, no filename sanitising and no metadata stripping.
     */
    public static function uploadAction(): Action
    {
        return Action::make('upload')
            ->label(__('Upload'))
            ->icon('heroicon-o-arrow-up-tray')
            ->authorize(fn (): bool => auth()->user()?->can('create', Media::class) ?? false)
            ->schema([
                FileUpload::make('files')
                    ->label(__('Files'))
                    ->multiple()
                    /*
                     * `storeFiles(false)` hands us the temporary upload instead
                     * of writing it somewhere itself — which is the whole point.
                     * Letting Filament store it would put an unsniffed,
                     * unsanitised file on a disk before anything had looked at
                     * it.
                     */
                    ->storeFiles(false)
                    /*
                     * A browser-side hint, and DELIBERATELY NOT the check.
                     *
                     * `acceptedFileTypes` filters the file picker and is a
                     * convenience for the person choosing files; it trusts the
                     * type the browser reports, so it decides nothing.
                     * `UploadPolicy` sniffs the bytes on the server, and that
                     * is what accepts or refuses.
                     */
                    ->acceptedFileTypes(static::acceptedMimeTypes())
                    /*
                     * No `AcceptableUpload` rule here, on purpose, and this is
                     * a real decision rather than an omission.
                     *
                     * A validation rule on a MULTIPLE field fails the whole
                     * field: one 90-pixel screenshot among thirty photographs
                     * from a project visit would reject all thirty, and the
                     * person would have to find the offender and re-upload the
                     * other twenty-nine over a Ghanaian mobile connection.
                     *
                     * So each file is passed to `MediaLibrary::add()`
                     * individually below, and a refusal is reported per file
                     * while the rest are kept. Nothing is weakened by this —
                     * the same UploadPolicy refuses the same files, in the same
                     * place, before anything is stored.
                     */
                    ->helperText(__(
                        'Images are checked by their contents rather than their name, and have camera '
                        .'metadata — including where the photograph was taken — removed before they '
                        .'are stored.'
                    )),

                Select::make('folder_id')
                    ->label(__('Folder'))
                    ->options(fn (): array => MediaFolder::orderBy('path')->pluck('name', 'id')->all())
                    ->default(fn (): ?int => app(MediaLibrary::class)->defaultFolder()->getKey())
                    ->required(),
            ])
            ->action(function (array $data): void {
                $folder = MediaFolder::find($data['folder_id']);
                $added = 0;
                $refused = [];

                foreach ((array) ($data['files'] ?? []) as $file) {
                    if (! $file instanceof TemporaryUploadedFile) {
                        continue;
                    }

                    try {
                        app(MediaLibrary::class)->add($file, $folder, actor: auth()->user());
                        $added++;
                    } catch (Throwable $e) {
                        /*
                         * One refusal does not abandon the batch. Somebody
                         * adding thirty photographs from a project visit should
                         * not lose twenty-nine of them because one was a
                         * screenshot at 90 pixels wide.
                         */
                        $refused[] = $file->getClientOriginalName().' — '.$e->getMessage();
                    }
                }

                if ($added > 0) {
                    Notification::make()
                        ->title(trans_choice('1 file added|:count files added', $added, ['count' => $added]))
                        ->body(__('Add alt text before using them on a page.'))
                        ->success()
                        ->send();
                }

                foreach ($refused as $reason) {
                    Notification::make()
                        ->title(__('One file was not accepted'))
                        ->body($reason)
                        ->warning()
                        ->persistent()
                        ->send();
                }
            });
    }

    /**
     * Replacing the file behind a row, keeping its id.
     *
     * The safe operation, and the one people actually mean when they say
     * "delete this and upload the new one": every reference follows, all at
     * once, instead of thirty of them pointing at a row that no longer exists.
     */
    private static function replaceAction(): Action
    {
        return Action::make('replace')
            ->label(__('Replace file'))
            ->icon('heroicon-o-arrow-path')
            ->authorize(fn (Media $record): bool => auth()->user()?->can('update', $record) ?? false)
            ->modalHeading(fn (Media $record): string => __('Replace :name', ['name' => $record->name]))
            ->modalDescription(fn (Media $record): string => __(
                'Everything already using this file will show the new one immediately. :usage',
                ['usage' => app(MediaUsage::class)->explain(
                    $record,
                    auth()->user()?->can('viewAny', Beneficiary::class) ?? false,
                )],
            ))
            ->schema([
                FileUpload::make('file')
                    ->label(__('New file'))
                    ->storeFiles(false)
                    ->rules([new AcceptableUpload])
                    ->required(),
            ])
            ->action(function (Media $record, array $data): void {
                $file = $data['file'] ?? null;

                if (! $file instanceof TemporaryUploadedFile) {
                    return;
                }

                try {
                    app(MediaLibrary::class)->replace($record, $file, auth()->user());

                    Notification::make()
                        ->title(__('File replaced'))
                        ->body(__('Everything using it now shows the new file.'))
                        ->success()
                        ->send();
                } catch (Throwable $e) {
                    Notification::make()
                        ->title(__('Not replaced'))
                        ->body($e->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();
                }
            });
    }

    /**
     * Deleting, which is expected to fail for anything in use.
     *
     * The guard lives in `Media::deleting()` so it holds for every path. Here
     * it is caught and turned into an explanation, because a red exception page
     * would read as a bug rather than as the system refusing on purpose.
     */
    private static function deleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->modalDescription(fn (Media $record): string => $record->isInUse()
                ? app(MediaUsage::class)->explain(
                    $record,
                    auth()->user()?->can('viewAny', Beneficiary::class) ?? false,
                )
                : __('Nothing is using this file, so deleting it will not break anything.'))
            ->action(function (Media $record): void {
                try {
                    $record->delete();

                    Notification::make()->title(__('Deleted'))->success()->send();
                } catch (MediaInUse $e) {
                    Notification::make()
                        ->title(__('This file is still in use'))
                        ->body($e->getMessage().' '.__(
                            'Remove it from those places first, or use "Replace file" — which keeps '
                            .'every reference and updates them all at once.'
                        ))
                        ->warning()
                        ->persistent()
                        ->send();
                }
            });
    }

    /**
     * The MIME types the file picker offers, from the same config the server
     * check reads.
     *
     * One source, so the picker cannot drift from the policy and start offering
     * a type that is then refused on upload — which reads as a broken form
     * rather than as a rule.
     *
     * @return array<int, string>
     */
    private static function acceptedMimeTypes(): array
    {
        /** @var array<string, array<string, array<int, string>>> $accept */
        $accept = config('media.accept', []);

        return array_values(array_unique(array_merge(...array_map(
            static fn (array $types): array => array_keys($types),
            array_values($accept),
        ))));
    }
}
