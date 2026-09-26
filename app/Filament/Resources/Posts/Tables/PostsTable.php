<?php

declare(strict_types=1);

namespace App\Filament\Resources\Posts\Tables;

use App\Enums\PageStatus;
use App\Filament\Support\ExportAction;
use App\Models\Post;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The news list.
 *
 * Newest first, which is the one content type where "recently updated" is
 * genuinely how people look for something — unlike pages, where the structure
 * matters more than the date.
 *
 * The status column carries the publish date as its description, because
 * "Scheduled" on its own does not answer the question somebody opened this
 * screen to ask, which is *when*.
 */
class PostsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('published_at', 'desc')
            // The title's description reads the category (same strict-Eloquent rule).
            ->modifyQueryUsing(fn (Builder $query) => $query->with('category'))
            ->columns([
                TextColumn::make('title')
                    ->label(__('Headline'))
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->description(fn (Post $record): ?string => $record->category?->name),

                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (PageStatus $state): string => $state->label())
                    ->color(fn (PageStatus $state): string => $state->colour())
                    ->description(fn (Post $record): ?string => $record->published_at?->toFormattedDayDateString())
                    ->sortable(),

                TextColumn::make('author.name')
                    ->label(__('By'))
                    ->toggleable()
                    // A post whose author account was deleted keeps the post;
                    // the byline is nullable for exactly that reason.
                    ->placeholder(__('No byline')),

                IconColumn::make('is_featured')
                    ->label(__('Featured'))
                    ->boolean()
                    ->toggleable(),

                TextColumn::make('comment_count')
                    ->label(__('Comments'))
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('view_count')
                    ->label(__('Views'))
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')
                    ->label(__('Last edited'))
                    ->since()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options(PageStatus::options()),

                SelectFilter::make('blog_category_id')
                    ->label(__('Category'))
                    ->relationship('category', 'name'),

                TernaryFilter::make('is_featured')->label(__('Featured')),

                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                ExportAction::make('report.generated', __('news posts'), [
                    'Headline' => 'title',
                    'Address' => 'slug',
                    'Status' => 'status',
                    'Published' => 'published_at',
                    'Category' => fn ($record) => $record->category?->name,
                    'Author' => fn ($record) => $record->author?->name,
                    'Views' => 'view_count',
                ], ['category', 'author']),
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
