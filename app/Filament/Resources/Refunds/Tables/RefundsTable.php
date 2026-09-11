<?php

declare(strict_types=1);

namespace App\Filament\Resources\Refunds\Tables;

use App\Models\Refund;
use App\Payments\RefundService;
use App\Support\AuditLogger;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

/**
 * Refunds, awaiting approval first.
 *
 * ── Approve is the critical action on this screen ───────────────────────────
 *
 * It sends money out. The model refuses the person who requested the refund,
 * so approval is always a second person, and the audit log records the
 * approval at critical severity because approval — not payment — is the
 * decision an auditor traces to a person.
 */
class RefundsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['transaction.payable', 'requestedBy', 'approvedBy']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label(__('Requested'))->dateTime('j M Y, H:i')->sortable(),
                TextColumn::make('payable')
                    ->label(__('Against'))
                    ->state(fn (Refund $r): string => ($r->transaction?->payable?->reference ?? '—'))
                    ->fontFamily('mono')
                    ->description(fn (Refund $r): string => class_basename((string) $r->transaction?->payable_type)),
                TextColumn::make('amount')->label(__('Amount'))->alignEnd()->state(fn (Refund $r): string => $r->amount->format()),
                TextColumn::make('reason')->label(__('Why'))->wrap()->limit(80),
                TextColumn::make('requestedBy.name')->label(__('Requested by'))->placeholder('—'),
                TextColumn::make('approvedBy.name')->label(__('Approved by'))->placeholder('—'),
                TextColumn::make('status')->label(__('Status'))->badge()->color(fn (string $state): string => match ($state) {
                    Refund::STATUS_REQUESTED => 'warning',
                    Refund::STATUS_PENDING => 'info',
                    Refund::STATUS_PROCESSED => 'success',
                    Refund::STATUS_FAILED => 'danger',
                    default => 'gray',
                }),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options([
                    Refund::STATUS_REQUESTED => __('Awaiting approval'),
                    Refund::STATUS_PENDING => __('Sent to gateway'),
                    Refund::STATUS_PROCESSED => __('Processed'),
                    Refund::STATUS_FAILED => __('Failed'),
                    Refund::STATUS_CANCELLED => __('Cancelled'),
                ])->default(Refund::STATUS_REQUESTED),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label(__('Approve and send'))
                    ->icon('heroicon-o-check-badge')
                    ->color('danger')
                    ->visible(fn (Refund $r): bool => $r->status === Refund::STATUS_REQUESTED && auth()->user()->can('donations.refund'))
                    ->requiresConfirmation()
                    ->modalHeading(fn (Refund $r): string => __('Send :amount back?', ['amount' => $r->amount->format()]))
                    ->modalDescription(fn (Refund $r): string => __('Requested by :who: ":reason". Approving sends it to the gateway now. It cannot be approved by the person who requested it.', ['who' => $r->requestedBy?->name ?? '—', 'reason' => $r->reason]))
                    ->action(function (Refund $r): void {
                        try {
                            $refund = app(RefundService::class)->approveAndExecute($r, auth()->user());
                        } catch (RuntimeException $e) {
                            Notification::make()->title($e->getMessage())->danger()->persistent()->send();

                            return;
                        }

                        app(AuditLogger::class)->record('refund.approved', 'Refund of '.$refund->amount->format().' approved', $refund, auth()->user(), ['status' => $refund->status]);

                        Notification::make()
                            ->title($refund->status === Refund::STATUS_FAILED ? __('The gateway refused it') : __('Sent to the gateway'))
                            ->body($refund->failure_reason ?? __('Status: :status', ['status' => $refund->status]))
                            ->{$refund->status === Refund::STATUS_FAILED ? 'danger' : 'success'}()
                            ->send();
                    }),

                Action::make('cancel')
                    ->label(__('Cancel request'))
                    ->icon('heroicon-o-x-mark')
                    ->color('gray')
                    ->visible(fn (Refund $r): bool => $r->status === Refund::STATUS_REQUESTED)
                    ->requiresConfirmation()
                    ->action(fn (Refund $r) => $r->forceFill(['status' => Refund::STATUS_CANCELLED])->save()),

                ViewAction::make(),
            ]);
    }
}
