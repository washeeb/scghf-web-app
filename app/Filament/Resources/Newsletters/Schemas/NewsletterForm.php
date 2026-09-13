<?php

declare(strict_types=1);

namespace App\Filament\Resources\Newsletters\Schemas;

use App\Models\Newsletter;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

/**
 * A list: what it is called, what topic a subscriber ticks to get it, who
 * it comes from. The description and cadence are what the preference
 * centre shows, so they are written for a reader, not for staff.
 */
class NewsletterForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('The list'))->columns(2)->schema([
                TextInput::make('name')->label(__('Name'))->required()->maxLength(191)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn ($state, callable $set, ?Newsletter $record) => $record === null ? $set('key', Str::slug((string) $state)) : null),
                TextInput::make('key')->label(__('Key'))->required()->maxLength(64)->alphaDash()->unique(ignoreRecord: true)
                    ->disabled(fn (?Newsletter $record): bool => $record !== null)->dehydrated(),
                TextInput::make('topic')->label(__('Topic'))->required()->maxLength(64)->alphaDash()
                    ->helperText(__('What a subscriber ticks in the preference centre. Two lists may share a topic.')),
                TextInput::make('cadence')->label(__('How often'))->maxLength(64)->helperText(__('"Monthly", "When there is news". Shown to subscribers.')),
                Textarea::make('description')->label(__('One line for subscribers'))->rows(2)->columnSpanFull(),
            ]),
            Section::make(__('Sending'))->columns(3)->collapsed()->schema([
                TextInput::make('from_name')->label(__('From name'))->maxLength(191),
                TextInput::make('from_address')->label(__('From address'))->email()->maxLength(191),
                TextInput::make('reply_to')->label(__('Reply to'))->email()->maxLength(191),
                Grid::make(2)->columnSpanFull()->schema([
                    Toggle::make('is_active')->label(__('Open for subscribers'))->default(true),
                    TextInput::make('sort_order')->label(__('Order'))->numeric()->default(0),
                ]),
            ]),
        ]);
    }
}
