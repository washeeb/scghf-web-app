<?php

declare(strict_types=1);

namespace App\Filament\Resources\FaqCategories\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class FaqCategoryForm
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

            Textarea::make('description')
                ->label(__('Description'))
                ->rows(2)
                ->helperText(__('Optional. A line under the heading on the FAQ page.')),

            Grid::make(2)->schema([
                TextInput::make('icon')
                    ->label(__('Icon'))
                    ->maxLength(64)
                    ->helperText(__('A Heroicon name, e.g. heroicon-o-heart. Leave empty for none.')),

                TextInput::make('sort_order')
                    ->label(__('Order'))
                    ->numeric()
                    ->default(0),
            ]),

            Toggle::make('is_published')
                ->label(__('Show on the site'))
                ->default(true),
        ]);
    }
}
