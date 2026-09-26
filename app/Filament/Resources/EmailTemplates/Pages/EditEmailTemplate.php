<?php

declare(strict_types=1);

namespace App\Filament\Resources\EmailTemplates\Pages;

use App\Communications\MessageDispatcher;
use App\Communications\SampleVariables;
use App\Filament\Resources\EmailTemplates\EmailTemplateResource;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * Editing one email, with two ways of seeing it.
 *
 * Preview draws the SAVED template inside the real layout with sample
 * values, in a modal. Send test posts it to the signed-in editor's own
 * address through the ordinary path — log row, suppression check, the
 * configured mailer — so "it looked fine in the preview" and "it arrived"
 * are two different facts and both can be checked.
 */
class EditEmailTemplate extends EditRecord
{
    protected static string $resource = EmailTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('preview')
                ->label(__('Preview'))
                ->icon('heroicon-o-eye')
                ->color('gray')
                ->modalHeading(fn (): string => $this->template()->subject)
                ->modalContent(fn (): HtmlString => new HtmlString(sprintf(
                    '<iframe title="%s" srcdoc="%s" style="width:100%%;height:70vh;border:0;background:#fff;border-radius:8px;"></iframe>',
                    e(__('Preview')),
                    e($this->template()->preview()),
                )))
                ->modalWidth('4xl')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel(__('Close')),

            Action::make('sendTest')
                ->label(__('Send me a test'))
                ->icon('heroicon-o-paper-airplane')
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription(fn (): string => __('Sends the saved template, with sample values, to :email through the real mailer.', ['email' => auth()->user()->email]))
                ->action(function (): void {
                    $template = $this->template();

                    try {
                        $log = app(MessageDispatcher::class)->sendEmailNow($template->key, auth()->user()->email, SampleVariables::for($template->available_variables ?? []), [
                            'to_name' => auth()->user()->name,
                            'user_id' => auth()->id(),
                            'unsubscribe_url' => url('/example'),
                        ]);
                    } catch (Throwable $e) {
                        Notification::make()->title(__('Not sent'))->body($e->getMessage())->danger()->persistent()->send();

                        return;
                    }

                    $log->status === EmailLog::STATUS_SENT
                        ? Notification::make()->title(__('Sent to :email', ['email' => auth()->user()->email]))->success()->send()
                        : Notification::make()->title(__('Not sent: :status', ['status' => $log->status]))->body($log->blocked_reason ?? $log->error)->warning()->persistent()->send();
                }),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['updated_by'] = auth()->id();

        return $data;
    }

    private function template(): EmailTemplate
    {
        /** @var EmailTemplate $record */
        $record = $this->getRecord();

        return $record->refresh();
    }
}
