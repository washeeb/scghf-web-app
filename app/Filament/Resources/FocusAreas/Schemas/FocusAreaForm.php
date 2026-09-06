<?php

declare(strict_types=1);

namespace App\Filament\Resources\FocusAreas\Schemas;

use App\Models\Division;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

/**
 * A thematic area of the foundation's work.
 *
 * `division_id` is required here and nullable almost everywhere else: a focus
 * area is a subdivision of one division's work and means nothing detached from
 * it. The schema says so, and this form does not offer an empty option.
 */
class FocusAreaForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(2)->schema([
                TextInput::make('name')
                    ->label(__('Name'))
                    ->required()
                    ->maxLength(191)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (?string $state, callable $set) => $set('slug', Str::slug((string) $state))),

                TextInput::make('slug')
                    ->label(__('Address'))
                    ->required()
                    ->maxLength(191)
                    ->unique(ignoreRecord: true),
            ]),

            Select::make('division_id')
                ->label(__('Part of'))
                ->options(fn (): array => Division::query()->orderBy('name')->pluck('name', 'id')->all())
                ->required()
                ->searchable()
                ->helperText(__('Which arm of the foundation this work sits under.')),

            Textarea::make('description')
                ->label(__('What this covers'))
                ->rows(3)
                ->helperText(__('Shown on the "What we do" page and at the top of the area page. Two or three sentences.')),

            Grid::make(3)->schema([
                TextInput::make('icon')
                    ->label(__('Icon'))
                    ->maxLength(64)
                    ->helperText(__('A Heroicon name, e.g. heroicon-o-academic-cap.')),

                TextInput::make('sort_order')->label(__('Order'))->numeric()->default(0),

                Toggle::make('is_active')
                    ->label(__('Show on the site'))
                    ->default(true)
                    ->helperText(__('An area with no published project yet is still worth listing — it is a true statement about the foundation.')),
            ]),
        ]);
    }
}
