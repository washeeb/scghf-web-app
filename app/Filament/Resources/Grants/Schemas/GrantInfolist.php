<?php

declare(strict_types=1);

namespace App\Filament\Resources\Grants\Schemas;

use App\Models\Grant;
use App\ValueObjects\Money;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * The grant at a glance: where it is, what was asked and awarded, and —
 * from the ledger, never typed — what has been spent against it.
 */
class GrantInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $money = fn (mixed $state): string => $state instanceof Money ? $state->format() : '—';

        return $schema->components([
            Section::make(__('The grant'))->columns(3)->schema([
                TextEntry::make('funder.name')->label(__('Funder')),
                TextEntry::make('status')->label(__('Status'))->badge()
                    ->formatStateUsing(fn (?string $state): string => Grant::STATUSES[$state] ?? (string) $state)
                    ->color(fn (?string $state): string => match ($state) {
                        Grant::STATUS_AWARDED => 'success',
                        Grant::STATUS_DECLINED => 'danger',
                        Grant::STATUS_SUBMITTED => 'warning',
                        Grant::STATUS_CLOSED => 'gray',
                        default => 'info',
                    }),
                TextEntry::make('owner.name')->label(__('Owner'))->placeholder(__('Nobody')),
                TextEntry::make('project.title')->label(__('Project'))->placeholder('—'),
                TextEntry::make('division.name')->label(__('Division'))->placeholder('—'),
                TextEntry::make('funder_reference')->label(__('Funder’s reference'))->placeholder('—'),
                TextEntry::make('deadline_on')->label(__('Application deadline'))->date('j F Y')->placeholder('—'),
                TextEntry::make('submitted_on')->label(__('Submitted'))->date('j F Y')->placeholder('—'),
                TextEntry::make('decided_on')->label(__('Decided'))->date('j F Y')->placeholder('—'),
                TextEntry::make('starts_on')->label(__('Period starts'))->date('j F Y')->placeholder('—'),
                TextEntry::make('ends_on')->label(__('Period ends'))->date('j F Y')->placeholder('—'),
                TextEntry::make('is_restricted')->label(__('Restricted'))->formatStateUsing(fn (mixed $state): string => $state ? __('Yes — spend only on its purpose') : __('No — unrestricted')),
            ]),

            Section::make(__('Money'))->columns(4)->schema([
                TextEntry::make('amount_requested')->label(__('Asked for'))->formatStateUsing($money)->placeholder('—'),
                TextEntry::make('amount_awarded')->label(__('Awarded'))->formatStateUsing($money)->placeholder('—'),
                TextEntry::make('spent')->label(__('Spent (paid)'))->state(fn (Grant $record): string => $record->spent()->format()),
                TextEntry::make('remaining')->label(__('Remaining'))->state(fn (Grant $record): string => $record->remaining()?->format() ?? '—')
                    ->helperText(fn (Grant $record): string => __('After :committed committed and not yet paid.', ['committed' => $record->committed()->format()]))
                    ->color(fn (Grant $record): string => ($record->remaining()?->isNegative() ?? false) ? 'danger' : 'gray'),
            ])->description(__('Spend is what the payouts ledger shows charged to this grant. Raise a payout under Finance → Payouts and choose the grant.')),

            Section::make(__('In writing'))->schema([
                TextEntry::make('purpose')->label(__('What it is for'))->prose()->placeholder('—'),
                TextEntry::make('notes')->label(__('Notes'))->prose()->placeholder('—'),
            ])->collapsed(),
        ]);
    }
}
