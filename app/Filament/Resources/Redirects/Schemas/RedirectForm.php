<?php

declare(strict_types=1);

namespace App\Filament\Resources\Redirects\Schemas;

use App\Models\Redirect;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * One redirect — or one recorded 404 waiting to become one.
 *
 * ── They are the same record ────────────────────────────────────────────────
 *
 * A captured 404 is a redirect with no destination yet. Filling in the
 * destination and switching it on is the entire "404-to-redirect workflow" the
 * blueprint asks for — no import, no second screen, no copying a path from one
 * list into another and mistyping it.
 *
 * ── The referrer is the clue, so it is shown ────────────────────────────────
 *
 * "Where did they come from?" is usually what tells an editor what the path was
 * meant to be: an old newsletter, a partner's page, a flyer with a shortened
 * link. It is on the record for that reason, and displayed here rather than
 * left in a column nobody scrolls to.
 */
class RedirectForm
{
    /** @return array<int, string> */
    public static function statusCodes(): array
    {
        return [
            301 => __('301 — moved for good'),
            302 => __('302 — moved for now'),
            410 => __('410 — deliberately removed'),
        ];
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('The old address'))->schema([
                TextInput::make('from_path')
                    ->label(__('From'))
                    ->required()
                    ->maxLength(191)
                    ->prefix(url('/'))
                    ->unique(ignoreRecord: true)
                    ->helperText(__('The address that no longer works — /old-page. Trailing slashes are tidied up for you.')),

                TextEntry::make('last_referrer')
                    ->label(__('Visitors arrived from'))
                    ->visible(fn (?Redirect $record): bool => filled($record?->last_referrer))
                    ->helperText(__('Usually the clue to what this address was meant to be.')),
            ]),

            Section::make(__('Where it should go'))->schema([
                Select::make('status_code')
                    ->label(__('Kind'))
                    ->options(static::statusCodes())
                    ->default(301)
                    ->required()
                    ->live()
                    ->helperText(__('"Moved for good" is nearly always right. Search engines pass the old page\'s standing on to the new one.')),

                TextInput::make('to_path')
                    ->label(__('To'))
                    ->maxLength(500)
                    /*
                     * Required except for 410, which is the one status that
                     * means "there is deliberately nothing here". The model
                     * enforces the same rule, so this only stops somebody
                     * meeting it as an exception on save.
                     */
                    ->required(fn (Get $get): bool => (int) $get('status_code') !== 410)
                    ->visible(fn (Get $get): bool => (int) $get('status_code') !== 410)
                    ->helperText(__('A path on this site — /new-page — or a full address elsewhere.')),

                Grid::make(2)->schema([
                    Toggle::make('is_active')
                        ->label(__('Switched on'))
                        ->helperText(__('A captured 404 arrives switched off. Turn it on once you have said where it goes.')),

                    Toggle::make('preserve_query')
                        ->label(__('Keep the ?parameters'))
                        ->helperText(__('Off by default. Carrying a two-year-old newsletter\'s tracking tag onto the new page credits today\'s donation to a campaign that ended.')),
                ]),

                Textarea::make('notes')
                    ->label(__('Notes'))
                    ->rows(2)
                    ->helperText(__('Why this exists. In a year nobody will remember.')),
            ]),

            Section::make(__('Use'))
                ->collapsed()
                ->visible(fn (?Redirect $record): bool => $record !== null)
                ->schema([
                    Grid::make(3)->schema([
                        TextEntry::make('hits')
                            ->label(__('Times used'))
                            // A redirect nobody has followed in a year is one
                            // that can go; a 404 hit four hundred times is the
                            // one to fix first.
                            ->helperText(__('A path hit often is the one worth fixing first.')),

                        TextEntry::make('last_hit_at')
                            ->label(__('Last used'))
                            ->dateTime()
                            ->placeholder(__('Never')),

                        TextEntry::make('source')
                            ->label(__('Added by'))
                            ->formatStateUsing(fn (?string $state): string => match ($state) {
                                'auto_404' => __('Recorded automatically'),
                                'import' => __('Imported'),
                                default => __('Entered by hand'),
                            }),
                    ]),
                ]),
        ]);
    }
}
