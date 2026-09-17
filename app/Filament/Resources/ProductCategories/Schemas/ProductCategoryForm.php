<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProductCategories\Schemas;

use App\Filament\Support\MediaPicker;
use App\Filament\Support\SeoFields;
use App\Models\ProductCategory;
use App\Shop\RegulatoryScreener;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

/**
 * A shop category.
 *
 * ── The policy key is the trustees' taxonomy, not a tag ─────────────────────
 *
 * `config('compliance.shop.approved_categories')` lists the kinds of goods the
 * board agreed the shop may sell. Marking a category with one of them says
 * "this is inside that agreement". Leaving it empty is allowed — a foundation
 * can add a category the policy did not anticipate — but the list shows it as
 * outside the taxonomy, and that is a question for the trustees rather than a
 * blocker for the editor.
 */
class ProductCategoryForm
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

            Grid::make(2)->schema([
                Select::make('parent_id')
                    ->label(__('Inside'))
                    ->options(fn (?ProductCategory $record): array => ProductCategory::query()
                        ->roots()
                        ->when($record, fn ($q) => $q->whereKeyNot($record->getKey()))
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->helperText(__('Optional. One level only: "T-shirts" inside "Clothing".')),

                Select::make('policy_key')
                    ->label(__('Kind of goods'))
                    ->options(fn (): array => collect(app(RegulatoryScreener::class)->approvedCategories())
                        ->map(fn (array $c): string => (string) $c['label'])
                        ->all())
                    ->helperText(__('The kinds the trustees agreed the shop may sell. Leave empty for something outside that list — it is allowed, and it is flagged.')),
            ]),

            Textarea::make('description')
                ->label(__('Description'))
                ->rows(2)
                ->helperText(__('Shown at the top of the category page, and used as its search description.')),

            MediaPicker::image('image_id')->label(__('Image')),

            Grid::make(2)->schema([
                TextInput::make('sort_order')
                    ->label(__('Order'))
                    ->numeric()
                    ->default(0),

                Toggle::make('is_active')
                    ->label(__('Show in the shop'))
                    ->default(true)
                    ->helperText(__('Hiding a category does not unpublish the products in it — they stay on sale, just not listed under this heading.')),
            ]),

            SeoFields::section(),
        ]);
    }
}
