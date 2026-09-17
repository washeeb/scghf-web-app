<?php

declare(strict_types=1);

namespace App\Filament\Resources\Media\Pages;

use App\Filament\Resources\Media\MediaResource;
use App\Models\Media;
use App\Support\AuditLogger;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use RuntimeException;

/**
 * Describing a file.
 *
 * No delete action in the header: deleting is on the list row, where the
 * refusal — and the explanation of what is using the file — has room to be
 * read. A delete button here would be one click from a form somebody opened to
 * fix a typo in a caption.
 */
class EditMedia extends EditRecord
{
    protected static string $resource = MediaResource::class;

    /**
     * `decorative` lives in `custom_properties`, not in a column.
     *
     * That is spatie's place for per-file flags and `Media::isDecorative()`
     * already reads it from there, so the form hydrates the toggle from it and
     * writes it back here rather than adding a column that would then have two
     * sources of truth.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('withdraw')
                ->label(__('Withdraw this image'))
                ->icon('heroicon-o-eye-slash')
                ->color('danger')
                ->visible(fn (): bool => ! $this->getRecord()->isWithdrawn())
                ->modalDescription(__('Takes it off every page, gallery and card at once. The file and the record stay, with the reason, so it can be reinstated and so the decision is on record.'))
                ->schema([
                    Textarea::make('reason')->label(__('Why'))->required()->rows(3),
                ])
                ->action(function (array $data): void {
                    try {
                        $this->getRecord()->withdraw(auth()->user(), (string) $data['reason']);
                    } catch (RuntimeException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();

                        return;
                    }

                    app(AuditLogger::class)->record('media.withdrawn', sprintf('Withdrew media #%d (%s): %s', $this->getRecord()->getKey(), $this->getRecord()->name, $data['reason']), $this->getRecord());
                    Notification::make()->title(__('Withdrawn. It no longer appears anywhere on the site.'))->warning()->send();
                    $this->refreshFormData(['publishable']);
                }),

            Action::make('reinstate')
                ->label(__('Reinstate'))
                ->icon('heroicon-o-eye')
                ->color('success')
                ->visible(fn (): bool => $this->getRecord()->isWithdrawn())
                ->requiresConfirmation()
                ->action(function (): void {
                    $this->getRecord()->reinstate();
                    app(AuditLogger::class)->record('media.reinstated', sprintf('Reinstated media #%d (%s).', $this->getRecord()->getKey(), $this->getRecord()->name), $this->getRecord());
                    Notification::make()->title(__('Reinstated.'))->success()->send();
                    $this->refreshFormData(['publishable']);
                }),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var Media $record */
        $record = $this->getRecord();

        $record->setCustomProperty('decorative', (bool) ($this->data['decorative'] ?? false));
        $record->save();

        return $data;
    }
}
