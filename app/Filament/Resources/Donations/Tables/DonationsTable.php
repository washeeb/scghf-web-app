<?php

declare(strict_types=1);

namespace App\Filament\Resources\Donations\Tables;

use App\Enums\DonationStatus;
use App\Filament\Support\ExportAction;
use App\Models\Cause;
use App\Models\Donation;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The donations list.
 *
 * ── Searchable by the things a donor quotes on the phone ────────────────────
 *
 * Reference, name, email, phone. "I gave last Tuesday and got no receipt" is
 * answered by typing whichever of those they can remember.
 *
 * ── Filters are the reports people actually run ─────────────────────────────
 *
 * A date range, an appeal, a channel, a status, an amount band, regular or
 * one-off. Anything that needs more than that is the Reports page.
 *
 * ── Personal details in the export are gated ────────────────────────────────
 *
 * The CSV carries a name and an email only for somebody holding
 * `donations.view_pii`; everybody else gets the reference, the amount and the
 * appeal, which is what a finance reconciliation needs and all it needs.
 */
class DonationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['cause', 'donor']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('reference')
                    ->label(__('Reference'))
                    ->fontFamily('mono')
                    ->searchable()
                    ->description(fn (Donation $record): string => $record->created_at->format('j M Y, H:i')),

                TextColumn::make('donor_name')
                    ->label(__('Donor'))
                    ->searchable(['donor_name', 'donor_email', 'donor_phone'])
                    ->formatStateUsing(fn (?string $state, Donation $record): string => $record->is_anonymous
                        ? ($state ?? '—').' '.__('(anonymous)')
                        : ($state ?? '—'))
                    ->description(fn (Donation $record): ?string => auth()->user()?->can('donations.view_pii') ? $record->donor_email : null),

                TextColumn::make('cause.title')
                    ->label(__('Appeal'))
                    ->wrap()
                    ->placeholder(__('General Fund')),

                TextColumn::make('amount')
                    ->label(__('Amount'))
                    ->alignEnd()
                    ->state(fn (Donation $record): string => $record->amount->format())
                    ->description(fn (Donation $record): ?string => $record->fee_covered_by_donor ? __('fee covered') : null)
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderBy('amount_minor', $direction)),

                TextColumn::make('channel')
                    ->label(__('Via'))
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'mobile_money' => __('Mobile Money'),
                        'card' => __('Card'),
                        'offline' => __('Offline'),
                        'bank' => __('Bank'),
                        null => '—',
                        default => ucfirst($state),
                    })
                    ->toggleable(),

                IconColumn::make('wants_recurring')
                    ->label(__('Regular'))
                    ->boolean()
                    ->state(fn (Donation $record): bool => $record->subscription_id !== null)
                    ->toggleable(),

                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (DonationStatus $state): string => $state->label())
                    ->color(fn (DonationStatus $state): string => match ($state) {
                        DonationStatus::Completed => 'success',
                        DonationStatus::Pending => 'gray',
                        DonationStatus::Failed, DonationStatus::Abandoned => 'gray',
                        DonationStatus::Refunded => 'warning',
                        DonationStatus::NeedsReview => 'danger',
                        default => 'gray',
                    }),

                IconColumn::make('reconciled')
                    ->label(__('Reconciled'))
                    ->boolean()
                    ->state(fn (Donation $record): bool => $record->transaction?->reconciled_at !== null)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('period')
                    ->schema([
                        DatePicker::make('from')->label(__('From')),
                        DatePicker::make('until')->label(__('Until')),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $d) => $q->whereDate('created_at', '>=', $d))
                        ->when($data['until'] ?? null, fn (Builder $q, $d) => $q->whereDate('created_at', '<=', $d))),

                SelectFilter::make('cause_id')
                    ->label(__('Appeal'))
                    ->options(fn (): array => Cause::query()->orderBy('title')->pluck('title', 'id')->all())
                    ->searchable(),

                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options(collect(DonationStatus::cases())->mapWithKeys(fn (DonationStatus $s): array => [$s->value => $s->label()])->all()),

                SelectFilter::make('channel')
                    ->label(__('Via'))
                    ->options(['mobile_money' => __('Mobile Money'), 'card' => __('Card'), 'offline' => __('Offline')]),

                Filter::make('amount')
                    ->schema([
                        TextInput::make('min')->label(__('At least'))->prefix('GH₵')->numeric(),
                        TextInput::make('max')->label(__('At most'))->prefix('GH₵')->numeric(),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['min'] ?? null, fn (Builder $q, $v) => $q->where('amount_minor', '>=', (int) round(((float) $v) * 100)))
                        ->when($data['max'] ?? null, fn (Builder $q, $v) => $q->where('amount_minor', '<=', (int) round(((float) $v) * 100)))),

                TernaryFilter::make('regular')
                    ->label(__('Regular gift'))
                    ->queries(
                        true: fn (Builder $q) => $q->whereNotNull('subscription_id'),
                        false: fn (Builder $q) => $q->whereNull('subscription_id'),
                    ),

                Filter::make('needs_review')
                    ->label(__('Needs review'))
                    ->query(fn (Builder $query) => $query->where('status', DonationStatus::NeedsReview->value)),
            ])
            ->recordActions([ViewAction::make()])
            ->toolbarActions([
                ExportAction::make('report.generated', __('donations'), array_filter([
                    'Reference' => 'reference',
                    'Date' => fn (Donation $record) => ($record->paid_at ?? $record->created_at)->format('Y-m-d H:i'),
                    'Status' => fn (Donation $record) => $record->status->value,
                    'Amount' => fn (Donation $record) => $record->amount->format(),
                    'Fee' => fn (Donation $record) => $record->fee->format(),
                    'Net' => fn (Donation $record) => $record->net->format(),
                    'Appeal' => fn (Donation $record) => $record->cause?->title,
                    'Via' => 'channel',
                    'Regular' => fn (Donation $record) => $record->subscription_id !== null,
                    'Donor' => auth()->user()?->can('donations.view_pii') ? 'donor_name' : null,
                    'Email' => auth()->user()?->can('donations.view_pii') ? 'donor_email' : null,
                    'Source' => 'source',
                    'Gateway reference' => 'paystack_reference',
                ]), ['cause']),
            ]);
    }
}
