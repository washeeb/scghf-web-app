<?php

declare(strict_types=1);

namespace App\Filament\Resources\Payouts\Schemas;

use App\Models\Payout;
use App\ValueObjects\Money;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PayoutInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('The payment'))->columns(3)->schema([
                TextEntry::make('reference')->label(__('Reference'))->fontFamily('mono')->copyable(),
                TextEntry::make('status')->label(__('Status'))->badge()->color(fn (?string $state): string => match ($state) {
                    Payout::STATUS_PAID => 'success',
                    Payout::STATUS_REJECTED, Payout::STATUS_CANCELLED => 'danger',
                    Payout::STATUS_APPROVED => 'info',
                    Payout::STATUS_PENDING => 'warning',
                    default => 'gray',
                }),
                TextEntry::make('amount')->label(__('Amount'))->formatStateUsing(fn (mixed $state): string => $state instanceof Money ? $state->format() : '—')->weight('bold'),
                TextEntry::make('payee_name')->label(__('Paid to')),
                TextEntry::make('payee_reference')->label(__('Account / number'))->placeholder('—')->copyable(),
                TextEntry::make('method')->label(__('How'))->formatStateUsing(fn (?string $state): string => PayoutForm::METHODS[$state] ?? (string) $state),
                TextEntry::make('category')->label(__('Category'))->formatStateUsing(fn (?string $state): string => PayoutForm::CATEGORIES[$state] ?? (string) $state),
                TextEntry::make('purpose')->label(__('What for'))->columnSpan(2),
                TextEntry::make('notes')->label(__('Notes'))->placeholder('—')->columnSpanFull(),
                TextEntry::make('rejection_reason')->label(__('Reason'))->visible(fn (Payout $record): bool => filled($record->rejection_reason))->color('danger')->columnSpanFull(),
            ]),

            Section::make(__('Charged to'))->columns(3)->schema([
                TextEntry::make('division.name')->label(__('Division'))->placeholder('—'),
                TextEntry::make('project.title')->label(__('Project'))->placeholder('—'),
                TextEntry::make('cause.title')->label(__('Appeal'))->placeholder('—'),
                TextEntry::make('grant.title')->label(__('Grant'))->placeholder('—'),
                TextEntry::make('beneficiary.case_reference')->label(__('Beneficiary case'))->placeholder('—')->fontFamily('mono')
                    ->visible(fn (): bool => auth()->user()?->can('beneficiaries.view') ?? false),
            ]),

            Section::make(__('Who and when'))->columns(3)->schema([
                TextEntry::make('requester.name')->label(__('Requested by'))->placeholder('—')->helperText(fn (Payout $record): ?string => $record->requested_at?->format('j M Y, H:i')),
                TextEntry::make('approver.name')->label(__('Approved by'))->placeholder('—')->helperText(fn (Payout $record): ?string => $record->approved_at?->format('j M Y, H:i')),
                TextEntry::make('paid_at')->label(__('Paid'))->dateTime('j M Y, H:i')->placeholder('—'),
                TextEntry::make('evidence.file_name')->label(__('Evidence'))->placeholder(__('None attached'))->fontFamily('mono'),
            ]),
        ]);
    }
}
