<?php

declare(strict_types=1);

namespace App\Filament\Resources\NewsletterCampaigns\Pages;

use App\Communications\CampaignSender;
use App\Filament\Resources\NewsletterCampaigns\NewsletterCampaignResource;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\NewsletterCampaign;
use App\Support\AuditLogger;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\HtmlString;
use RuntimeException;
use Throwable;

/**
 * A campaign's life, as actions in the order they happen.
 *
 * Preview → Send me a test → Build the list → Approve (somebody with
 * `newsletter.send`) → Send now / Schedule → Pause / Resume / Cancel. The
 * model refuses out of order — no test, no approval, empty list — and the
 * refusal is shown as it is.
 */
class EditNewsletterCampaign extends EditRecord
{
    protected static string $resource = NewsletterCampaignResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('preview')
                ->label(__('Preview'))
                ->icon('heroicon-o-eye')
                ->color('gray')
                ->modalHeading(fn (): string => $this->campaign()->subject)
                ->modalContent(fn (): HtmlString => new HtmlString(sprintf(
                    '<iframe title="%s" srcdoc="%s" style="width:100%%;height:70vh;border:0;background:#fff;border-radius:8px;"></iframe>',
                    e(__('Preview')),
                    e($this->previewHtml()),
                )))
                ->modalWidth('4xl')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel(__('Close')),

            Action::make('sendTest')
                ->label(__('Send me a test'))
                ->icon('heroicon-o-paper-airplane')
                ->color('gray')
                ->visible(fn (): bool => ! in_array($this->campaign()->status, [NewsletterCampaign::STATUS_SENT, NewsletterCampaign::STATUS_CANCELLED], true))
                ->schema([
                    TextInput::make('to')->label(__('To'))->email()->required()->default(fn (): string => auth()->user()->email),
                ])
                ->action(function (array $data): void {
                    try {
                        $log = app(CampaignSender::class)->sendTest($this->campaign(), (string) $data['to']);
                    } catch (Throwable $e) {
                        Notification::make()->title(__('Not sent'))->body($e->getMessage())->danger()->persistent()->send();

                        return;
                    }

                    $log->status === EmailLog::STATUS_SENT
                        ? Notification::make()->title(__('Test sent to :to. Read it before approving.', ['to' => $data['to']]))->success()->send()
                        : Notification::make()->title(__('Not sent: :status', ['status' => $log->status]))->body($log->blocked_reason ?? $log->error)->warning()->persistent()->send();

                    $this->reload();
                }),

            Action::make('build')
                ->label(fn (): string => $this->campaign()->recipient_count > 0 ? __('Rebuild the list') : __('Build the list'))
                ->icon('heroicon-o-users')
                ->color('gray')
                ->visible(fn (): bool => in_array($this->campaign()->status, [NewsletterCampaign::STATUS_DRAFT, NewsletterCampaign::STATUS_SCHEDULED], true))
                ->requiresConfirmation()
                ->modalDescription(__('Works out who is on the list right now: confirmed subscribers to this list\'s topic, minus anybody suppressed. New subscribers are added on a rebuild; nobody is sent to twice.'))
                ->action(function (): void {
                    $count = app(CampaignSender::class)->build($this->campaign());

                    Notification::make()->title(__(':count recipients', ['count' => $count]))->success()->send();
                    $this->reload();
                }),

            Action::make('approve')
                ->label(__('Approve'))
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->visible(fn (): bool => auth()->user()->can('newsletter.send')
                    && $this->campaign()->approved_at === null
                    && ! in_array($this->campaign()->status, [NewsletterCampaign::STATUS_SENT, NewsletterCampaign::STATUS_CANCELLED], true))
                ->requiresConfirmation()
                ->modalDescription(__('Confirms you have read the test and the campaign may go. Any edit afterwards withdraws the approval.'))
                ->action(function (): void {
                    try {
                        $this->campaign()->approve(auth()->user());
                    } catch (RuntimeException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();

                        return;
                    }

                    app(AuditLogger::class)->record('newsletter.approved', 'Campaign "'.$this->campaign()->title.'" approved', $this->campaign(), auth()->user());
                    Notification::make()->title(__('Approved.'))->success()->send();
                    $this->reload();
                }),

            Action::make('schedule')
                ->label(fn (): string => $this->campaign()->scheduled_for && $this->campaign()->scheduled_for->isFuture() ? __('Confirm the schedule') : __('Send now'))
                ->icon('heroicon-o-rocket-launch')
                ->color('primary')
                ->visible(fn (): bool => auth()->user()->can('newsletter.send')
                    && $this->campaign()->status === NewsletterCampaign::STATUS_DRAFT)
                ->schema([
                    DateTimePicker::make('scheduled_for')
                        ->label(__('Send from'))
                        ->seconds(false)
                        ->default(fn () => $this->campaign()->scheduled_for ?? now())
                        ->required()
                        ->helperText(__('The cron sends in batches from this time, within the hourly allowance. A large list takes hours; the page shows progress.')),
                ])
                ->action(function (array $data): void {
                    $campaign = $this->campaign();
                    $campaign->forceFill(['scheduled_for' => $data['scheduled_for']])->save();

                    if ($campaign->recipient_count < 1) {
                        app(CampaignSender::class)->build($campaign);
                    }

                    try {
                        $campaign->refresh()->assertSendable();
                    } catch (RuntimeException $e) {
                        Notification::make()->title(__('Not scheduled'))->body($e->getMessage())->danger()->persistent()->send();

                        return;
                    }

                    $campaign->forceFill(['status' => NewsletterCampaign::STATUS_SCHEDULED])->save();
                    app(AuditLogger::class)->record('newsletter.scheduled', 'Campaign "'.$campaign->title.'" scheduled for '.$campaign->scheduled_for?->format('j M Y H:i'), $campaign, auth()->user(), ['recipients' => $campaign->recipient_count]);

                    Notification::make()->title(__('Scheduled for :when to :count people.', ['when' => $campaign->scheduled_for?->format('j M, H:i'), 'count' => $campaign->recipient_count]))->success()->send();
                    $this->reload();
                }),

            Action::make('pause')
                ->label(__('Pause'))
                ->icon('heroicon-o-pause')
                ->color('warning')
                ->visible(fn (): bool => in_array($this->campaign()->status, [NewsletterCampaign::STATUS_SCHEDULED, NewsletterCampaign::STATUS_SENDING], true))
                ->schema([Textarea::make('reason')->label(__('Why'))->required()->rows(2)])
                ->action(function (array $data): void {
                    $this->campaign()->pause((string) $data['reason']);
                    Notification::make()->title(__('Paused. Nothing more goes until it is resumed.'))->success()->send();
                    $this->reload();
                }),

            Action::make('resume')
                ->label(__('Resume'))
                ->icon('heroicon-o-play')
                ->color('success')
                ->visible(fn (): bool => $this->campaign()->status === NewsletterCampaign::STATUS_PAUSED)
                ->requiresConfirmation()
                ->action(function (): void {
                    $this->campaign()->resume();
                    Notification::make()->title(__('Resumed.'))->success()->send();
                    $this->reload();
                }),

            Action::make('cancel')
                ->label(__('Cancel'))
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (): bool => ! in_array($this->campaign()->status, [NewsletterCampaign::STATUS_SENT, NewsletterCampaign::STATUS_CANCELLED], true))
                ->schema([Textarea::make('reason')->label(__('Why'))->required()->rows(2)])
                ->action(function (array $data): void {
                    $this->campaign()->cancel((string) $data['reason']);
                    Notification::make()->title(__('Cancelled. What was already sent stays sent.'))->success()->send();
                    $this->reload();
                }),

            DeleteAction::make()->visible(fn (): bool => $this->campaign()->status === NewsletterCampaign::STATUS_DRAFT),
        ];
    }

    public function getSubheading(): ?string
    {
        $c = $this->campaign();

        return trim(implode(' · ', array_filter([
            __('Status: :status', ['status' => $c->status]),
            $c->test_sent_at ? __('tested :when', ['when' => $c->test_sent_at->diffForHumans()]) : __('not yet tested'),
            $c->approved_at ? __('approved by :who', ['who' => $c->approver?->name]) : __('not yet approved'),
            $c->recipient_count > 0 ? __(':sent of :total sent', ['sent' => $c->sent_count, 'total' => $c->recipient_count]) : null,
            $c->failure_reason,
        ])));
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (empty($data['blocks']) && blank($data['body_html'] ?? null)) {
            $data['body_html'] = '';
        }

        return $data;
    }

    private function previewHtml(): string
    {
        $campaign = $this->campaign();
        $template = EmailTemplate::forKey(CampaignSender::TEMPLATE_KEY);

        return $template->preview([
            'subject' => $campaign->subject,
            'content' => new HtmlString((string) $campaign->body_html),
            'content_text' => (string) $campaign->body_text,
            'preheader' => $campaign->preheader,
            'subscriber_name' => 'Ama',
            'unsubscribe_url' => url('/example'),
        ]);
    }

    private function reload(): void
    {
        $this->getRecord()->refresh();
        $this->fillForm();
    }

    private function campaign(): NewsletterCampaign
    {
        /** @var NewsletterCampaign $record */
        $record = $this->getRecord();

        return $record;
    }
}
