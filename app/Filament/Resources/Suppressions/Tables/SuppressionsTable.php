<?php

declare(strict_types=1);

namespace App\Filament\Resources\Suppressions\Tables;

use App\Communications\PhoneNumber;
use App\Models\Suppression;
use App\Support\AuditLogger;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;
use Throwable;

/**
 * The do-not-contact list.
 *
 * ── Two directions, two permissions ─────────────────────────────────────────
 *
 * Adding an address is `suppressions.view` — anybody who can see the list
 * may stop the foundation writing to somebody who asked. Releasing one is
 * `suppressions.release`, needs a reason, is refused outright for an
 * erasure request, and is audited: putting an address back after a bounce
 * or a complaint is what gets a sending domain blocklisted.
 */
class SuppressionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('releasedBy'))
            ->defaultSort('suppressed_at', 'desc')
            ->columns([
                TextColumn::make('address')->label(__('Address'))->searchable()->copyable()->fontFamily('mono'),
                TextColumn::make('channel')->label(__('Channel'))->badge()->color('gray'),
                TextColumn::make('scope')->label(__('Stops'))->badge()
                    ->formatStateUsing(fn (string $state): string => $state === Suppression::SCOPE_ALL ? __('everything') : __('marketing only'))
                    ->color(fn (string $state): string => $state === Suppression::SCOPE_ALL ? 'danger' : 'warning'),
                TextColumn::make('reason')->label(__('Why'))->badge()->color('gray')->formatStateUsing(fn (string $state): string => str_replace('_', ' ', $state)),
                TextColumn::make('detail')->label(__('Detail'))->limit(60)->wrap()->placeholder('—')->toggleable(),
                TextColumn::make('source')->label(__('Source'))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('occurrences')->label(__('Times'))->alignEnd()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('suppressed_at')->label(__('Since'))->dateTime('j M Y, H:i')->sortable(),
                TextColumn::make('released_at')->label(__('Released'))->dateTime('j M Y')->placeholder('—')->sortable()
                    ->description(fn (Suppression $r): ?string => $r->releasedBy?->name),
            ])
            ->filters([
                SelectFilter::make('channel')->label(__('Channel'))->options([Suppression::CHANNEL_EMAIL => __('Email'), Suppression::CHANNEL_SMS => __('SMS')]),
                SelectFilter::make('reason')->label(__('Why'))->options([
                    Suppression::REASON_HARD_BOUNCE => __('Hard bounce'),
                    Suppression::REASON_COMPLAINT => __('Complaint'),
                    Suppression::REASON_UNSUBSCRIBE => __('Unsubscribed / STOP'),
                    Suppression::REASON_INVALID => __('Invalid'),
                    Suppression::REASON_ERASURE => __('Erasure request'),
                    Suppression::REASON_MANUAL => __('Added by staff'),
                    Suppression::REASON_SOFT_BOUNCE => __('Repeated soft bounces'),
                ]),
                TernaryFilter::make('released')->label(__('Released'))
                    ->queries(true: fn (Builder $q) => $q->whereNotNull('released_at'), false: fn (Builder $q) => $q->whereNull('released_at'))
                    ->default(false),
            ])
            ->headerActions([
                Action::make('add')
                    ->label(__('Add an address'))
                    ->icon('heroicon-o-plus')
                    ->schema([
                        Select::make('channel')->label(__('Channel'))->options([Suppression::CHANNEL_EMAIL => __('Email'), Suppression::CHANNEL_SMS => __('SMS')])->required()->live(),
                        TextInput::make('address')->label(__('Address or number'))->required()->maxLength(191),
                        Select::make('scope')->label(__('Stop'))->options([Suppression::SCOPE_MARKETING => __('Marketing only — receipts still go'), Suppression::SCOPE_ALL => __('Everything')])->default(Suppression::SCOPE_MARKETING)->required(),
                        Textarea::make('detail')->label(__('Why'))->required()->rows(2)->helperText(__('"Asked by phone on 3 May". Kept on the record.')),
                    ])
                    ->action(function (array $data): void {
                        try {
                            $address = $data['channel'] === Suppression::CHANNEL_SMS ? PhoneNumber::normalise((string) $data['address']) : (string) $data['address'];
                            Suppression::record((string) $data['channel'], $address, Suppression::REASON_MANUAL, (string) $data['detail'], 'staff', auth()->user(), (string) $data['scope']);
                        } catch (Throwable $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title(__('Added. Nothing will be sent to it.'))->success()->send();
                    }),
            ])
            ->recordActions([
                Action::make('release')
                    ->label(__('Release'))
                    ->icon('heroicon-o-lock-open')
                    ->color('danger')
                    ->visible(fn (Suppression $r): bool => $r->released_at === null && $r->reason !== Suppression::REASON_ERASURE && auth()->user()->can('suppressions.release'))
                    ->modalDescription(__('Puts the address back into circulation. After a bounce or a complaint that is how a sending domain gets blocklisted; be sure, and say why.'))
                    ->schema([Textarea::make('reason')->label(__('Why'))->required()->rows(2)])
                    ->action(function (Suppression $r, array $data): void {
                        try {
                            $r->release(auth()->user(), (string) $data['reason']);
                        } catch (RuntimeException $e) {
                            Notification::make()->title($e->getMessage())->danger()->persistent()->send();

                            return;
                        }

                        app(AuditLogger::class)->record('suppression.released', 'Suppression released', $r, auth()->user(), ['channel' => $r->channel, 'reason' => $data['reason']]);
                        Notification::make()->title(__('Released.'))->success()->send();
                    }),
            ]);
    }
}
