<?php

declare(strict_types=1);

namespace App\Filament\Resources\ContactMessages\Tables;

use App\Communications\MessageDispatcher;
use App\Filament\Resources\ContactMessages\Schemas\ContactMessageForm;
use App\Models\ContactMessage;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Throwable;

/**
 * The inbox.
 *
 * ── Oldest first ────────────────────────────────────────────────────────────
 *
 * Backwards from every other list in this panel, and on purpose. An inbox
 * sorted newest-first buries the enquiry that has been waiting longest under
 * the ones that just arrived, which is precisely the message somebody needs to
 * see. The age column is red once a message is a week old.
 *
 * ── Replying is an action, not a page ───────────────────────────────────────
 *
 * Answering an enquiry is a thing somebody does to a message, not a record they
 * edit. The action sends the `contact.reply` template through the same
 * dispatcher as every other email — so it is logged, suppression-checked and
 * rate-limited like the rest, rather than being a `Mail::raw` that bypasses all
 * three.
 */
class ContactMessagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at')
            ->columns([
                TextColumn::make('name')
                    ->label(__('From'))
                    ->searchable()
                    ->description(fn (ContactMessage $record): string => (string) $record->email),

                TextColumn::make('subject')
                    ->label(__('About'))
                    ->searchable()
                    ->wrap()
                    ->limit(70)
                    ->placeholder(__('No subject'))
                    ->description(fn (ContactMessage $record): ?string => $record->department?->name),

                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => ContactMessageForm::statuses()[$state] ?? (string) $state)
                    ->color(fn (?string $state): string => match ($state) {
                        'new' => 'warning',
                        'assigned' => 'info',
                        'replied' => 'success',
                        'resolved' => 'gray',
                        default => 'danger',
                    })
                    ->sortable(),

                TextColumn::make('assignee.name')
                    ->label(__('Owner'))
                    ->placeholder(__('Nobody'))
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label(__('Waiting'))
                    ->since()
                    ->sortable()
                    /*
                     * A week is the line. A foundation that takes longer than
                     * that to answer a volunteer offer usually loses it, and
                     * the column is the only place anybody would notice.
                     */
                    ->color(fn (ContactMessage $record): string => in_array($record->status, ['new', 'assigned'], true)
                        && $record->created_at?->lt(now()->subWeek())
                            ? 'danger'
                            : 'gray'),

                IconColumn::make('consent_given')
                    ->label(__('Consent'))
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options(ContactMessageForm::statuses())
                    ->default('new'),

                SelectFilter::make('contact_department_id')
                    ->label(__('Department'))
                    ->relationship('department', 'name'),

                Filter::make('mine')
                    ->label(__('Assigned to me'))
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->where('assigned_to', auth()->id())),

                Filter::make('unanswered')
                    ->label(__('Waiting more than a week'))
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query
                        ->whereIn('status', ['new', 'assigned'])
                        ->where('created_at', '<', now()->subWeek())),
            ])
            ->recordActions([
                static::replyAction(),
                EditAction::make()->label(__('Open')),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    static::assignBulkAction(),
                    static::markResolvedBulkAction(),
                ]),
            ]);
    }

    /**
     * Answer the sender.
     *
     * ── It refuses to send twice by accident ───────────────────────────────
     *
     * Two people opening the inbox on a Monday morning and both replying to the
     * same enquiry is an ordinary thing to happen. The confirmation says who
     * replied and when, so the second person is told rather than discovering it
     * from a confused sender.
     */
    private static function replyAction(): Action
    {
        return Action::make('reply')
            ->label(__('Reply'))
            ->icon('heroicon-o-arrow-uturn-left')
            ->visible(fn (ContactMessage $record): bool => $record->status !== 'spam'
                && auth()->user()?->can('reply', $record) === true)
            ->modalHeading(fn (ContactMessage $record): string => __('Reply to :name', ['name' => $record->name]))
            ->modalDescription(fn (ContactMessage $record): ?string => $record->replied_at === null
                ? null
                : __('Careful — this was already answered :when.', [
                    'when' => $record->replied_at->diffForHumans(),
                ]))
            ->schema([
                Textarea::make('reply')
                    ->label(__('Your reply'))
                    ->required()
                    ->rows(8)
                    ->helperText(__('Sent from the foundation\'s address. Their original message is quoted underneath it automatically.')),
            ])
            ->action(function (ContactMessage $record, array $data): void {
                try {
                    app(MessageDispatcher::class)->sendEmailNow('contact.reply', $record->email, [
                        'name' => $record->name,
                        'reference' => $record->reference,
                        'reply' => $data['reply'],
                        'original_message' => $record->message,
                        'replied_by' => auth()->user()?->name ?? '',
                    ]);
                } catch (Throwable $e) {
                    Notification::make()
                        ->title(__('Not sent'))
                        ->body($e->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                /*
                 * `forceFill`, because `status`, `replied_at` and `replied_by`
                 * are deliberately outside `$fillable` — the public contact
                 * form creates these records from request input, and a guarded
                 * status is what stops somebody submitting one already marked
                 * resolved.
                 */
                $record->forceFill([
                    'status' => 'replied',
                    'replied_at' => now(),
                    'replied_by' => auth()->id(),
                ])->save();

                Notification::make()
                    ->title(__('Sent'))
                    ->body(__('Marked as replied.'))
                    ->success()
                    ->send();
            });
    }

    private static function assignBulkAction(): BulkAction
    {
        return BulkAction::make('assign')
            ->label(__('Assign to somebody'))
            ->icon('heroicon-o-user-plus')
            ->schema([
                Select::make('assigned_to')
                    ->label(__('Owner'))
                    ->options(fn (): array => User::query()
                        ->where('user_type', 'staff')
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->required(),
            ])
            ->action(function (Collection $records, array $data): void {
                $records->each(fn (ContactMessage $record) => $record->forceFill([
                    'assigned_to' => $data['assigned_to'],
                    // An assigned message is no longer new. Leaving the status
                    // alone would keep it in the default filter for whoever
                    // just handed it over.
                    'status' => $record->status === 'new' ? 'assigned' : $record->status,
                ])->save());
            })
            ->deselectRecordsAfterCompletion();
    }

    private static function markResolvedBulkAction(): BulkAction
    {
        return BulkAction::make('resolve')
            ->label(__('Mark resolved'))
            ->icon('heroicon-o-check-circle')
            ->requiresConfirmation()
            ->action(fn (Collection $records) => $records->each(
                fn (ContactMessage $record) => $record->forceFill(['status' => 'resolved'])->save()
            ))
            ->deselectRecordsAfterCompletion();
    }
}
