<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pages\Pages;

use App\Filament\Resources\Pages\PageResource;
use App\Models\Page;
use App\Models\PageRevision;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Radio;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Text;
use Illuminate\Support\Facades\URL;

/**
 * Editing a page, with a way back.
 *
 * ── A revision is taken BEFORE every save ───────────────────────────────────
 *
 * Not after. A revision recorded after the write is a record of the change that
 * has already happened — useful for an audit, useless for undoing anything. The
 * one taken before is the state somebody can return to, which is what "revision
 * history with restore" actually means.
 *
 * Restoring is itself snapshotted, so an editor who restores the wrong revision
 * is not stuck with it.
 */
class EditPage extends EditRecord
{
    protected static string $resource = PageResource::class;

    /**
     * Snapshot the page as it stands, before anything is written.
     *
     * `mutateFormDataBeforeSave` runs while the record still holds its old
     * values, which is exactly the moment worth recording.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var Page $page */
        $page = $this->getRecord();

        $page->snapshot(summary: null, user: auth()->user());

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            static::previewAction(),
            static::revisionsAction(),
            DeleteAction::make()
                // A locked page is one the application links to by slug —
                // deleting it breaks a route rather than removing content.
                ->visible(fn (Page $record): bool => ! $record->is_locked),
        ];
    }

    /**
     * See the page as a visitor would, whether or not it is published.
     *
     * -- Signed AND behind auth, both ---------------------------------------
     *
     * The obvious implementation is a secret URL, and a secret URL is a URL: it
     * ends up in a chat message, a browser history, a referrer header. The
     * signature proves the link came from the panel; the policy check in
     * `PageController::preview()` proves the person who opened it is entitled
     * to see an unpublished page. A leaked preview link is useless to anybody
     * outside the foundation.
     *
     * Twenty minutes, because a preview link is for looking at something now.
     */
    private static function previewAction(): Action
    {
        return Action::make('preview')
            ->label(__('Preview'))
            ->icon('heroicon-o-eye')
            ->url(fn (Page $record): string => URL::temporarySignedRoute(
                'pages.preview',
                now()->addMinutes(20),
                ['page' => $record->ulid],
            ))
            ->openUrlInNewTab();
    }

    /**
     * The history, and the way back.
     *
     * The list is deliberately plain: a revision number, who saved it and when.
     * A diff view would be better and is a much larger thing to build; what
     * matters first is that returning to yesterday's version is possible at all.
     */
    private static function revisionsAction(): Action
    {
        return Action::make('revisions')
            ->label(__('History'))
            ->icon('heroicon-o-clock')
            ->modalHeading(__('Earlier versions of this page'))
            ->modalDescription(__(
                'Restoring replaces the page and all its blocks with that version. The current '
                .'version is saved first, so this is undoable.'
            ))
            ->schema(fn (Page $record): array => [
                Text::make(
                    $record->revisions()->exists()
                        ? ''
                        : __('No earlier versions yet. One is saved every time you save this page.')
                ),

                Radio::make('revision')
                    ->label(__('Version'))
                    ->options(fn (): array => $record->revisions()
                        ->latest('revision_number')
                        ->limit(20)
                        ->get()
                        ->mapWithKeys(fn (PageRevision $revision): array => [
                            $revision->getKey() => sprintf(
                                '#%d — %s%s',
                                $revision->revision_number,
                                $revision->created_at?->format('j M Y, H:i') ?? '',
                                $revision->user?->name ? ' by '.$revision->user->name : '',
                            ),
                        ])
                        ->all())
                    ->visible(fn (): bool => $record->revisions()->exists()),
            ])
            ->action(function (array $data, Page $record): void {
                $revision = $record->revisions()->find($data['revision'] ?? null);

                if ($revision === null) {
                    return;
                }

                $revision->restore(auth()->user());

                Notification::make()
                    ->title(__('Restored version :number', ['number' => $revision->revision_number]))
                    ->body(__('The version you were on has been saved, so you can go back again.'))
                    ->success()
                    ->send();
            })
            ->modalSubmitActionLabel(__('Restore this version'));
    }
}
