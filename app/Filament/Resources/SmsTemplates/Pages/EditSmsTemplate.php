<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmsTemplates\Pages;

use App\Communications\MessageDispatcher;
use App\Communications\PhoneNumber;
use App\Communications\SampleVariables;
use App\Filament\Resources\SmsTemplates\SmsTemplateResource;
use App\Models\SmsLog;
use App\Models\SmsTemplate;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use Throwable;

/**
 * Editing one text. Preview shows the rendered message and its cost; Send
 * test posts it to a number the editor types, through the ordinary path,
 * so the log driver on a laptop and the real gateway on the server both
 * leave a row in the SMS log.
 */
class EditSmsTemplate extends EditRecord
{
    protected static string $resource = SmsTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('preview')
                ->label(__('Preview'))
                ->icon('heroicon-o-eye')
                ->color('gray')
                ->modalHeading(__('As it will read'))
                ->modalDescription(function (): string {
                    $template = $this->template();
                    $rendered = $template->render(SampleVariables::for($template->available_variables ?? []));

                    return $rendered."\n\n".$template->explain();
                })
                ->modalSubmitAction(false)
                ->modalCancelActionLabel(__('Close')),

            Action::make('sendTest')
                ->label(__('Send a test'))
                ->icon('heroicon-o-paper-airplane')
                ->color('gray')
                ->schema([
                    TextInput::make('to')
                        ->label(__('To'))
                        ->type('tel')
                        ->required()
                        ->default(fn (): ?string => auth()->user()->phone)
                        ->helperText(__('A Ghanaian number. It costs a real segment on the real gateway.')),
                ])
                ->action(function (array $data): void {
                    $template = $this->template();

                    try {
                        $number = PhoneNumber::normalise((string) $data['to']);
                        $log = app(MessageDispatcher::class)->sendSmsNow($template->key, $number, SampleVariables::for($template->available_variables ?? []), [
                            'user_id' => auth()->id(),
                        ]);
                    } catch (Throwable $e) {
                        Notification::make()->title(__('Not sent'))->body($e->getMessage())->danger()->persistent()->send();

                        return;
                    }

                    $log->status === SmsLog::STATUS_SENT
                        ? Notification::make()->title(__('Sent to :to (:segments segment(s))', ['to' => $number, 'segments' => $log->segments]))->success()->send()
                        : Notification::make()->title(__('Logged as :status', ['status' => $log->status]))->body($log->blocked_reason ?? $log->error ?? __('The log driver records without sending.'))->info()->persistent()->send();
                }),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['updated_by'] = auth()->id();

        return $data;
    }

    /**
     * The model refuses a body over its segment budget or a sender ID the
     * networks will not carry. Shown as it is, and the save stops.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return parent::handleRecordUpdate($record, $data);
        } catch (RuntimeException $e) {
            Notification::make()->title(__('Not saved'))->body($e->getMessage())->danger()->persistent()->send();

            $this->halt();
        }
    }

    private function template(): SmsTemplate
    {
        /** @var SmsTemplate $record */
        $record = $this->getRecord();

        return $record->refresh();
    }
}
