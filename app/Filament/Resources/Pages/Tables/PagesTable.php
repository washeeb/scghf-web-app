<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pages\Tables;

use App\Enums\PageStatus;
use App\Models\Page;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * The page list.
 *
 * Sorted by path rather than by date, so the site's structure is visible as a
 * structure — /about above /about/our-story above /about/leadership. A CMS list
 * ordered by "recently updated" tells an editor what they touched last, which
 * they already know, and hides the shape of the thing they are editing.
 */
class PagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('path')
            ->columns([
                TextColumn::make('title')
                    ->label(__('Page'))
                    ->searchable()
                    ->sortable()
                    ->description(fn (Page $record): string => (string) $record->path)
                    ->wrap(),

                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (PageStatus $state): string => $state->label())
                    ->color(fn (PageStatus $state): string => $state->colour())
                    ->sortable(),

                TextColumn::make('sections_count')
                    ->label(__('Blocks'))
                    ->counts('sections')
                    ->alignEnd()
                    /*
                     * A published page with no blocks is a blank page with a
                     * heading on it. It is the commonest way a CMS goes live
                     * looking broken, and it is invisible in a list that only
                     * shows a status.
                     */
                    ->color(fn (Page $record, int $state): string => $state === 0 && $record->isLive() ? 'danger' : 'gray'),

                IconColumn::make('is_homepage')
                    ->label(__('Home'))
                    ->boolean()
                    ->toggleable(),

                TextColumn::make('published_at')
                    ->label(__('Published'))
                    ->dateTime('j M Y, H:i')
                    ->sortable()
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

                TernaryFilter::make('empty')
                    ->label(__('Published with no blocks'))
                    ->placeholder(__('All pages'))
                    ->trueLabel(__('Only pages that would render blank'))
                    ->falseLabel(__('Only pages with blocks'))
                    ->queries(
                        true: fn ($query) => $query->whereDoesntHave('sections')->whereIn('status', ['published', 'scheduled']),
                        false: fn ($query) => $query->whereHas('sections'),
                        blank: fn ($query) => $query,
                    ),
            ])
            ->recordActions([
                EditAction::make(),
                self::viewAction(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Open the page on the site.
     *
     * Shown only for a page that is actually live, because a "view" button that
     * leads to a 404 teaches people the button is broken rather than that the
     * page is a draft. For a draft the preview belongs on the edit screen,
     * where the unsaved state lives.
     */
    private static function viewAction(): Action
    {
        return Action::make('view')
            ->label(__('View'))
            ->icon('heroicon-o-arrow-top-right-on-square')
            ->url(fn (Page $record): string => url((string) $record->path))
            ->openUrlInNewTab()
            ->visible(fn (Page $record): bool => $record->isLive());
    }
}
