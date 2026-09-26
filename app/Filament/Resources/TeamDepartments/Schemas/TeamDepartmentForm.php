<?php

declare(strict_types=1);

namespace App\Filament\Resources\TeamDepartments\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class TeamDepartmentForm
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
                ->rows(2),

            Grid::make(2)->schema([
                TextInput::make('sort_order')
                    ->label(__('Order'))
                    ->numeric()
                    ->default(0)
                    ->helperText(__('The order the groups appear in on the team page.')),

                Toggle::make('is_published')
                    ->label(__('Show on the site'))
                    ->default(true),
            ]),
        ]);
    }
}
