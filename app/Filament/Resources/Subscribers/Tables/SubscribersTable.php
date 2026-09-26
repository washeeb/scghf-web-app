<?php

declare(strict_types=1);

namespace App\Filament\Resources\Subscribers\Tables;

use App\Communications\MessageDispatcher;
use App\Filament\Support\ExportAction;
use App\Models\Newsletter;
use App\Models\Subscriber;
use App\Models\Suppression;
use App\Support\AuditLogger;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The mailing list, as a list.
 *
 * ── The consent is on the row ───────────────────────────────────────────────
 *
 * When they signed up, from which form, with what wording, and from which
 * address — because "did this person agree?" is the question Act 843 asks,
 * and the answer has to be on the record, not in somebody's memory.
 *
 * ── Staff take people OFF, never put them on ────────────────────────────────
 *
 * There is no create button. A subscriber is somebody who typed their own
 * address and clicked the confirmation; anything else is not consent.
 * Erasing is a delete; an erasure request also suppresses the address so
 * it cannot be re-enrolled by a form on a bad day.
 */
class SubscribersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('email')->label(__('Email'))->searchable()->copyable()
                    ->description(fn (Subscriber $r): ?string => $r->name),
                TextColumn::make('status')->label(__('Status'))->badge()->color(fn (string $state): string => match ($state) {
                    Subscriber::STATUS_CONFIRMED => 'success',
                    Subscriber::STATUS_PENDING => 'warning',
                    default => 'danger',
                }),
                TextColumn::make('topics')->label(__('Hears about'))->badge()->color('gray')
                    ->state(fn (Subscriber $r): array => $r->topics === null ? [__('everything')] : (array) $r->topics),
                TextColumn::make('source')->label(__('Signed up from'))->badge()->color('gray')->toggleable(),
                TextColumn::make('consent_at')->label(__('Consented'))->dateTime('j M Y, H:i')->placeholder('—')->sortable()
                    ->description(fn (Subscriber $r): ?string => $r->consent_ip),
                TextColumn::make('confirmed_at')->label(__('Confirmed'))->date('j M Y')->placeholder('—')->sortable()->toggleable(),
                TextColumn::make('last_emailed_at')->label(__('Last sent'))->since()->placeholder('—')->sortable()->toggleable(),
                TextColumn::make('bounce_count')->label(__('Bounces'))->alignEnd()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('unsubscribe_reason')->label(__('Left because'))->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options([
                    Subscriber::STATUS_CONFIRMED => __('Confirmed'),
                    Subscriber::STATUS_PENDING => __('Awaiting confirmation'),
                    Subscriber::STATUS_UNSUBSCRIBED => __('Unsubscribed'),
                    Subscriber::STATUS_BOUNCED => __('Bounced'),
                    Subscriber::STATUS_COMPLAINED => __('Complained'),
                ])->default(Subscriber::STATUS_CONFIRMED),
                SelectFilter::make('topic')->label(__('Topic'))
                    ->options(fn (): array => Newsletter::query()->pluck('name', 'topic')->all())
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $q, string $topic) => $q
                        ->where(fn (Builder $w) => $w->whereNull('topics')->orWhereJsonContains('topics', $topic)))),
                SelectFilter::make('source')->label(__('Source'))
                    ->options(fn (): array => Subscriber::query()->distinct()->pluck('source', 'source')->all()),
            ])
            ->recordActions([
                Action::make('resendConfirmation')
                    ->label(__('Resend confirmation'))
                    ->icon('heroicon-o-envelope')
                    ->visible(fn (Subscriber $r): bool => $r->status === Subscriber::STATUS_PENDING)
                    ->requiresConfirmation()
                    ->action(function (Subscriber $r): void {
                        app(MessageDispatcher::class)->queueEmail('newsletter.confirm', $r->email, [
                            'name' => $r->name ?? '',
                            'confirm_url' => route('newsletter.confirm', $r->confirmation_token),
                            'newsletter_name' => (string) setting('general.short_name'),
                        ], ['to_name' => $r->name, 'related' => $r, 'idempotency_key' => 'newsletter.confirm:'.$r->getKey().':'.now()->timestamp]);

                        Notification::make()->title(__('Queued.'))->success()->send();
                    }),

                Action::make('unsubscribe')
                    ->label(__('Unsubscribe'))
                    ->icon('heroicon-o-minus-circle')
                    ->color('warning')
                    ->visible(fn (Subscriber $r): bool => in_array($r->status, [Subscriber::STATUS_CONFIRMED, Subscriber::STATUS_PENDING], true))
                    ->schema([Textarea::make('reason')->label(__('Why'))->required()->rows(2)->helperText(__('"Asked by phone on 3 May". Kept on the record.'))])
                    ->action(function (Subscriber $r, array $data): void {
                        $r->unsubscribe('staff: '.$data['reason']);
                        Notification::make()->title(__('Unsubscribed.'))->success()->send();
                    }),

                DeleteAction::make()
                    ->label(__('Erase'))
                    ->modalHeading(__('Erase this subscriber'))
                    ->modalDescription(__('For an erasure request under Act 843. The address is also suppressed so no form can put it back; the fact of the erasure is audited without the address.'))
                    ->before(function (Subscriber $r): void {
                        Suppression::record(Suppression::CHANNEL_EMAIL, $r->email, Suppression::REASON_ERASURE, 'Erased from the subscriber list', 'staff', auth()->user(), Suppression::SCOPE_MARKETING);
                        app(AuditLogger::class)->record('erasure.completed', 'Subscriber erased', null, auth()->user(), ['subscriber' => $r->getKey()]);
                    }),
            ])
            ->toolbarActions([
                ExportAction::make('report.generated', __('subscribers'), [
                    'Email' => 'email',
                    'Name' => 'name',
                    'Status' => 'status',
                    'Topics' => fn (Subscriber $r) => $r->topics === null ? 'all' : implode(', ', (array) $r->topics),
                    'Source' => 'source',
                    'Consented' => fn (Subscriber $r) => $r->consent_at?->format('Y-m-d H:i'),
                    'Confirmed' => fn (Subscriber $r) => $r->confirmed_at?->format('Y-m-d'),
                ]),
            ]);
    }
}
