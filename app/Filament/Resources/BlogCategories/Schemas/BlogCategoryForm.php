<?php

declare(strict_types=1);

namespace App\Filament\Resources\BlogCategories\Schemas;

use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class BlogCategoryForm
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
                    ->unique(ignoreRecord: true)
                    ->helperText(__('Used in the web address for this category.')),
            ]),

            Textarea::make('description')
                ->label(__('Description'))
                ->rows(2)
                ->helperText(__('Shown at the top of the category page, and used as its search description.')),

            Grid::make(2)->schema([
                /*
                 * A colour, not a theme token.
                 *
                 * Deliberately different from the rest of the CMS, where colour
                 * is a closed vocabulary. A category badge is a small, isolated
                 * accent that carries no text of its own, so a bad choice
                 * cannot make anything illegible — and a foundation with an
                 * "Emergency appeals" category has a real reason to want it
                 * red. Empty means the theme's own accent.
                 */
                ColorPicker::make('colour')
                    ->label(__('Badge colour'))
                    ->helperText(__('Optional. Leave empty to use the site accent.')),

                TextInput::make('sort_order')
                    ->label(__('Order'))
                    ->numeric()
                    ->default(0)
                    ->helperText(__('Lower numbers come first.')),
            ]),

            Toggle::make('is_published')
                ->label(__('Show on the site'))
                ->default(true)
                ->helperText(__('Hiding a category does not hide the posts in it — they stay published, just not listed under this heading.')),
        ]);
    }
}
