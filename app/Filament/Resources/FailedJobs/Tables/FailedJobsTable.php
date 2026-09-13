<?php

declare(strict_types=1);

namespace App\Filament\Resources\FailedJobs\Tables;

use App\Models\FailedJob;
use App\Support\AuditLogger;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

/**
 * The two things you can do with a failed job, on a host with no terminal.
 *
 * Retry puts it back on the queue with Laravel's own command; the cron
 * worker picks it up within the minute. Discard removes it. Both are
 * audited, because a retried job is money or a message going out again.
 */
class FailedJobsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('failed_at', 'desc')
            ->columns([
                TextColumn::make('job')->label(__('Job'))->state(fn (FailedJob $r): string => class_basename($r->displayName()))
                    ->description(fn (FailedJob $r): string => $r->displayName()),
                TextColumn::make('reason')->label(__('Why'))->state(fn (FailedJob $r): string => $r->reason())->wrap()->limit(160),
                TextColumn::make('queue')->label(__('Queue'))->badge()->color('gray')->toggleable(),
                TextColumn::make('failed_at')->label(__('Failed'))->dateTime('j M Y, H:i')->sortable(),
            ])
            ->recordActions([
                Action::make('retry')
                    ->label(__('Retry'))
                    ->icon('heroicon-o-arrow-path')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalDescription(__('Puts it back on the queue. The cron worker picks it up within the minute; if it fails again it will be back here.'))
                    ->action(function (FailedJob $r): void {
                        $name = $r->displayName();
                        $r->retry();
                        app(AuditLogger::class)->record('queue.job_retried', 'Retried '.$name, null, auth()->user(), ['uuid' => $r->uuid]);
                        Notification::make()->title(__('Back on the queue.'))->success()->send();
                    }),
                Action::make('discard')
                    ->label(__('Discard'))
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription(__('Removes it for good. Whatever it was going to do will not happen.'))
                    ->action(function (FailedJob $r): void {
                        $name = $r->displayName();
                        $r->forget();
                        app(AuditLogger::class)->record('queue.job_discarded', 'Discarded '.$name, null, auth()->user(), ['uuid' => $r->uuid]);
                        Notification::make()->title(__('Discarded.'))->success()->send();
                    }),
            ])
            ->toolbarActions([
                BulkAction::make('retryAll')
                    ->label(__('Retry selected'))
                    ->icon('heroicon-o-arrow-path')
                    ->requiresConfirmation()
                    ->action(function (Collection $records): void {
                        $records->each(fn (FailedJob $r) => $r->retry());
                        app(AuditLogger::class)->record('queue.job_retried', 'Retried '.$records->count().' failed jobs', null, auth()->user(), ['count' => $records->count()]);
                        Notification::make()->title(__(':count back on the queue.', ['count' => $records->count()]))->success()->send();
                    })
                    ->deselectRecordsAfterCompletion(),
            ]);
    }
}
