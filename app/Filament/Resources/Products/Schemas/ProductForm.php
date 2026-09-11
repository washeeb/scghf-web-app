<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Schemas;

use App\Filament\Support\MediaPicker;
use App\Filament\Support\MoneyField;
use App\Models\Cause;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Shop\RegulatoryScreener;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

/**
 * A product and its variants.
 *
 * ── Prices are typed in cedis here, and stored in pesewas ───────────────────
 *
 * The rest of the admin takes money in pesewas with a helper showing the
 * cedis. A shop is different: somebody pricing thirty items types "45.00"
 * thirty times, and asking for "4500" thirty times is asking for one of them
 * to be "450". `Money::ofMajor()` is the only conversion, on the way out;
 * `MoneyCast` receives an integer of minor units and would throw on anything
 * else, which is what stops a stray string being written as pesewas.
 *
 * ── Every product has at least one variant ──────────────────────────────────
 *
 * Even one with no choices to make. The variant is what carries the price,
 * the SKU and the stock, and a product with none is a product the shop cannot
 * sell — so the repeater refuses to save with zero rows.
 *
 * ── Stock is not a field ────────────────────────────────────────────────────
 *
 * The figure is shown, read-only, beside each variant. Changing it is an
 * action on the page — restock, or adjust with a note — because stock is a
 * ledger and a number typed over another number is a movement with no reason.
 *
 * ── The regulatory screen is the model's, not the form's ────────────────────
 *
 * Every save screens the text against the prohibited-keyword list, and a
 * flagged product is unpublished until a review is recorded. The form shows
 * the flag; the action on the edit page records the review; nothing here can
 * bypass either.
 */
class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make()->columnSpanFull()->tabs([
                Tabs\Tab::make(__('The product'))->schema([
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

                    Grid::make(3)->schema([
                        Select::make('product_category_id')
                            ->label(__('Category'))
                            ->options(fn (): array => ProductCategory::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable(),

                        Select::make('product_type')
                            ->label(__('Kind'))
                            ->options([
                                Product::TYPE_PHYSICAL => __('Something posted or collected'),
                                Product::TYPE_DIGITAL => __('A download'),
                            ])
                            ->default(Product::TYPE_PHYSICAL)
                            ->required(),

                        Select::make('cause_id')
                            ->label(__('Proceeds fund'))
                            ->options(fn (): array => Cause::query()->orderBy('title')->pluck('title', 'id')->all())
                            ->searchable()
                            ->helperText(__('Optional. "Proceeds fund the borehole" is the reason to buy here rather than at a market stall.')),
                    ]),

                    Textarea::make('summary')
                        ->label(__('One-line summary'))
                        ->rows(2)
                        ->maxLength(300)
                        ->helperText(__('Under the name on the product page, and in search results.')),

                    RichEditor::make('description')
                        ->label(__('Description'))
                        ->toolbarButtons(['bold', 'italic', 'link', 'bulletList', 'orderedList', 'h3', 'undo', 'redo']),

                    MediaPicker::image('featured_image_id')->label(__('Main image')),

                    TextEntry::make('regulatory')
                        ->label(__('Regulatory screen'))
                        ->visible(fn (?Product $record): bool => $record?->requires_regulatory_review ?? false)
                        ->state(fn (Product $record): string => $record->needsRegulatoryReview()
                            ? __('Flagged (:flags) and not yet reviewed. It cannot be published until a review is recorded — use the action at the top of the page. :notice', [
                                'flags' => implode(', ', $record->regulatoryFlags()),
                                'notice' => app(RegulatoryScreener::class)->notice(),
                            ])
                            : __('Flagged (:flags); review recorded on :date, reference :reference.', [
                                'flags' => implode(', ', $record->regulatoryFlags()),
                                'date' => $record->regulatory_reviewed_at?->format('j M Y'),
                                'reference' => $record->regulatory_reference,
                            ]))
                        ->color(fn (Product $record): string => $record->needsRegulatoryReview() ? 'danger' : 'success'),
                ]),

                Tabs\Tab::make(__('Options, prices and stock'))->schema([
                    Repeater::make('variants')
                        ->label('')
                        ->relationship()
                        ->minItems(1)
                        ->defaultItems(1)
                        ->reorderable()
                        ->orderColumn('sort_order')
                        ->collapsible()
                        ->addActionLabel(__('Add an option'))
                        ->itemLabel(fn (array $state): ?string => trim(($state['name'] ?? '').' '.($state['sku'] ?? '')) ?: null)
                        ->helperText(__('One row per thing that can be bought — a size, a colour. A product with only one kind still has one row; leave its name empty.'))
                        ->schema([
                            Grid::make(3)->schema([
                                TextInput::make('name')
                                    ->label(__('Option'))
                                    ->maxLength(191)
                                    ->helperText(__('"Large / Navy". Empty for a product with no options.')),

                                TextInput::make('sku')
                                    ->label(__('SKU'))
                                    ->required()
                                    ->maxLength(64)
                                    ->distinct()
                                    ->unique(table: ProductVariant::class, column: 'sku', ignoreRecord: true)
                                    ->helperText(__('Your own code for it, unique across the shop.')),

                                TextEntry::make('stock')
                                    ->label(__('In stock'))
                                    ->state(fn (?ProductVariant $record): string => $record === null
                                        ? __('Recorded once saved')
                                        : ($record->tracks_stock
                                            ? __(':sellable available (:held held for unpaid orders)', ['sellable' => $record->sellableQuantity(), 'held' => $record->stock_held])
                                            : __('Not tracked')))
                                    ->helperText(__('Changed with "Adjust stock" at the top of the page — never by typing over it.')),
                            ]),

                            Grid::make(3)->schema([
                                MoneyField::make('price')
                                    ->label(__('Price'))
                                    ->required(),

                                MoneyField::make('compare_at_price')
                                    ->label(__('Was'))
                                    ->helperText(__('Optional. Shown struck through beside the price.')),

                                TextInput::make('weight_grams')
                                    ->label(__('Weight'))
                                    ->suffix('g')
                                    ->numeric()
                                    ->minValue(0)
                                    ->helperText(__('Used to pick the delivery rate.')),
                            ]),

                            Grid::make(3)->schema([
                                Toggle::make('tracks_stock')
                                    ->label(__('Track stock'))
                                    ->default(true)
                                    ->helperText(__('Off for something unlimited — a download.')),

                                Toggle::make('allow_backorder')
                                    ->label(__('Sell when out of stock'))
                                    ->helperText(__('Orders keep coming in and are fulfilled when stock arrives.')),

                                Toggle::make('is_active')
                                    ->label(__('On sale'))
                                    ->default(true),
                            ]),

                            KeyValue::make('options')
                                ->label(__('Attributes'))
                                ->keyLabel(__('Attribute'))
                                ->valueLabel(__('Value'))
                                ->helperText(__('Optional: size → L, colour → navy. For your records and for filters later.')),
                        ]),
                ]),

                Tabs\Tab::make(__('More images'))->schema([
                    Repeater::make('images')
                        ->label('')
                        ->relationship()
                        ->reorderable()
                        ->orderColumn('sort_order')
                        ->collapsible()
                        ->defaultItems(0)
                        ->addActionLabel(__('Add an image'))
                        ->schema([
                            MediaPicker::image('media_id')->label(__('Image'))->required(),
                            TextInput::make('alt_text')
                                ->label(__('Describe it'))
                                ->maxLength(191)
                                ->helperText(__('Optional. The library\'s own alt text is used if this is empty.')),
                        ]),
                ]),

                Tabs\Tab::make(__('Publishing'))->schema([
                    Grid::make(2)->schema([
                        Toggle::make('is_published')
                            ->label(__('On sale in the shop'))
                            ->helperText(__('A product that trips the regulatory screen is taken off again until a review is recorded.')),

                        DateTimePicker::make('published_at')
                            ->label(__('From'))
                            ->seconds(false)
                            ->helperText(__('Leave empty to publish immediately.')),
                    ]),

                    Grid::make(2)->schema([
                        Toggle::make('is_featured')->label(__('Feature it'))->helperText(__('Featured products come first.')),
                        TextInput::make('sort_order')->label(__('Order'))->numeric()->default(0),
                    ]),
                ]),
            ]),
        ]);
    }
}
