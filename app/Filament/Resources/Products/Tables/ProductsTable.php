<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Tables;

use App\Filament\Support\ExportAction;
use App\Models\Product;
use App\Models\ProductVariant;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The catalogue.
 *
 * ── Stock and the regulatory flag are the two columns that matter ───────────
 *
 * "Low" is measured against `shop.low_stock_threshold`, because ten tote bags
 * is plenty and ten of a bracelet that sells thirty a week is a stockout on
 * Thursday. A product awaiting regulatory review is shown in red in the list
 * and has its own filter, because it is the one state where the shop is
 * silently NOT selling something an editor believes is on sale.
 */
class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['category', 'variants', 'cause']))
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                TextColumn::make('name')
                    ->label(__('Product'))
                    ->searchable()
                    ->wrap()
                    ->description(fn (Product $record): ?string => $record->category?->name),

                TextColumn::make('price')
                    ->label(__('Price'))
                    ->alignEnd()
                    ->state(fn (Product $record): string => $record->fromPrice()?->format() ?? '—')
                    ->description(fn (Product $record): ?string => $record->variants->count() > 1
                        ? trans_choice('{1}:count option|[2,*]:count options', $record->variants->count(), ['count' => $record->variants->count()])
                        : null),

                TextColumn::make('stock')
                    ->label(__('Stock'))
                    ->alignEnd()
                    ->state(fn (Product $record): string => self::stockLabel($record))
                    ->color(fn (Product $record): string => self::stockColour($record)),

                TextColumn::make('regulatory')
                    ->label(__('Regulatory'))
                    ->state(fn (Product $record): string => match (true) {
                        $record->needsRegulatoryReview() => __('Needs review'),
                        $record->requires_regulatory_review => __('Reviewed'),
                        default => __('Clear'),
                    })
                    ->color(fn (Product $record): string => match (true) {
                        $record->needsRegulatoryReview() => 'danger',
                        $record->requires_regulatory_review => 'warning',
                        default => 'gray',
                    })
                    ->toggleable(),

                IconColumn::make('is_published')
                    ->label(__('On sale'))
                    ->boolean()
                    ->state(fn (Product $record): bool => $record->isLive()),

                IconColumn::make('is_featured')->label(__('Featured'))->boolean()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('product_category_id')->label(__('Category'))->relationship('category', 'name'),
                TernaryFilter::make('is_published')->label(__('Published')),
                Filter::make('needs_review')
                    ->label(__('Awaiting regulatory review'))
                    ->query(fn (Builder $query) => $query->awaitingRegulatoryReview()),
                Filter::make('low_stock')
                    ->label(__('Low or out of stock'))
                    ->query(fn (Builder $query) => $query->whereHas(
                        'variants',
                        fn (Builder $q) => $q->where('tracks_stock', true)
                            ->whereRaw('stock_on_hand - stock_held <= ?', [(int) setting('shop.low_stock_threshold', 5)]),
                    )),
                TrashedFilter::make(),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([
                ExportAction::make('report.generated', __('products'), [
                    'Product' => 'name',
                    'Category' => fn (Product $record) => $record->category?->name,
                    'SKUs' => fn (Product $record) => $record->variants->pluck('sku')->implode(', '),
                    'From price' => fn (Product $record) => $record->fromPrice()?->format(),
                    'Available' => fn (Product $record) => self::stockLabel($record),
                    'On sale' => fn (Product $record) => $record->isLive(),
                    'Regulatory' => fn (Product $record) => $record->needsRegulatoryReview() ? 'needs review' : ($record->requires_regulatory_review ? 'reviewed' : 'clear'),
                ], ['category', 'variants']),
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ]);
    }

    private static function stockLabel(Product $record): string
    {
        $tracked = $record->variants->where('tracks_stock', true);

        if ($tracked->isEmpty()) {
            return __('not tracked');
        }

        return (string) $tracked->sum(fn (ProductVariant $v): int => $v->sellableQuantity());
    }

    private static function stockColour(Product $record): string
    {
        $tracked = $record->variants->where('tracks_stock', true);

        if ($tracked->isEmpty()) {
            return 'gray';
        }

        $available = $tracked->sum(fn (ProductVariant $v): int => $v->sellableQuantity());

        return match (true) {
            $available === 0 => 'danger',
            $available <= (int) setting('shop.low_stock_threshold', 5) => 'warning',
            default => 'gray',
        };
    }
}
