<?php

declare(strict_types=1);

namespace App\Filament\Resources\Donors\Tables;

use App\Filament\Support\ExportAction;
use App\Models\Donor;
use App\ValueObjects\Money;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The donor list: who gives, how much over their lifetime, when they last did.
 *
 * ── "Lapsed" is the filter worth having ─────────────────────────────────────
 *
 * A donor who gave every month and stopped is the person most worth a
 * personal note, and the list is sorted by last gift so the top is the
 * newest and the bottom is who to write to.
 *
 * ── The export is for a mailing, and it is audited as one ───────────────────
 *
 * It carries only donors who consented to email, because that is the only use
 * it has, and the audit log records who took it and how many rows.
 */
class DonorsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('tags'))
            ->defaultSort('last_donated_at', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->label(__('Donor'))
                    ->searchable(['name', 'email', 'phone', 'organisation_name'])
                    ->description(fn (Donor $record): ?string => $record->email),

                TextColumn::make('total_donated_minor')
                    ->label(__('Lifetime'))
                    ->alignEnd()
                    ->state(fn (Donor $record): string => Money::ofMinor((int) $record->total_donated_minor)->format())
                    ->sortable(),

                TextColumn::make('donation_count')->label(__('Gifts'))->alignEnd()->sortable(),

                TextColumn::make('first_donated_at')->label(__('First gift'))->date('j M Y')->placeholder('—')->sortable()->toggleable(),
                TextColumn::make('last_donated_at')->label(__('Last gift'))->date('j M Y')->placeholder('—')->sortable(),

                TextColumn::make('consent')
                    ->label(__('Consent'))
                    ->state(fn (Donor $record): string => implode(' · ', array_filter([$record->consent_email ? __('email') : null, $record->consent_sms ? __('SMS') : null])) ?: '—')
                    ->toggleable(),

                TextColumn::make('donor_type')->label(__('Type'))->badge()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('tags.name')->label(__('Tags'))->badge()->color('gray')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('tags')->label(__('Tag'))->relationship('tags', 'name')->multiple()->preload(),
                TernaryFilter::make('consent_email')->label(__('Consented to email')),
                SelectFilter::make('donor_type')->label(__('Type'))->options([
                    Donor::TYPE_INDIVIDUAL => __('Individual'),
                    Donor::TYPE_ORGANISATION => __('Organisation'),
                ]),
                Filter::make('lapsed')
                    ->label(__('Lapsed — no gift in twelve months'))
                    ->query(fn (Builder $query) => $query->where('donation_count', '>', 0)->where('last_donated_at', '<', now()->subYear())),
                Filter::make('regular')
                    ->label(__('Has an active regular gift'))
                    ->query(fn (Builder $query) => $query->whereHas('subscriptions', fn (Builder $q) => $q->where('status', 'active'))),
                Filter::make('lifetime')
                    ->schema([TextInput::make('min')->label(__('Lifetime at least'))->prefix('GH₵')->numeric()])
                    ->query(fn (Builder $query, array $data) => $query->when($data['min'] ?? null, fn (Builder $q, $v) => $q->where('total_donated_minor', '>=', (int) round(((float) $v) * 100)))),
            ])
            ->recordActions([ViewAction::make(), EditAction::make()])
            ->toolbarActions([
                ExportAction::make('report.generated', __('donors who consented to email'), [
                    'Name' => 'name',
                    'Email' => 'email',
                    'Lifetime' => fn (Donor $record) => Money::ofMinor((int) $record->total_donated_minor)->format(),
                    'Gifts' => 'donation_count',
                    'Last gift' => fn (Donor $record) => $record->last_donated_at?->format('Y-m-d'),
                ]),
            ]);
    }
}
