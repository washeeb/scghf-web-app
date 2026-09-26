<?php

declare(strict_types=1);

namespace App\Filament\Resources\EmailTemplates\Tables;

use App\Models\EmailTemplate;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class EmailTemplatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('editor')->withCount('logs'))
            ->defaultSort('key')
            ->columns([
                TextColumn::make('name')->label(__('Template'))->searchable(['name', 'key', 'subject'])
                    ->description(fn (EmailTemplate $r): string => $r->key),
                TextColumn::make('subject')->label(__('Subject'))->limit(60)->wrap(),
                TextColumn::make('category')->label(__('Category'))->badge()->color(fn (string $state): string => match ($state) {
                    EmailTemplate::CATEGORY_MARKETING => 'warning',
                    EmailTemplate::CATEGORY_SYSTEM => 'gray',
                    default => 'success',
                }),
                TextColumn::make('logs_count')->label(__('Sent'))->alignEnd()->sortable(),
                IconColumn::make('is_active')->label(__('Sends'))->boolean(),
                IconColumn::make('is_locked')->label(__('Locked'))->boolean()->trueIcon('heroicon-o-lock-closed')->falseIcon('heroicon-o-lock-open')->toggleable(),
                TextColumn::make('updated_at')->label(__('Edited'))->since()->sortable()
                    ->description(fn (EmailTemplate $r): ?string => $r->editor?->name),
            ])
            ->filters([
                SelectFilter::make('category')->label(__('Category'))->options([
                    EmailTemplate::CATEGORY_TRANSACTIONAL => __('Transactional'),
                    EmailTemplate::CATEGORY_MARKETING => __('Marketing'),
                    EmailTemplate::CATEGORY_SYSTEM => __('System'),
                ]),
                TernaryFilter::make('is_active')->label(__('Sends')),
            ])
            ->recordActions([EditAction::make()]);
    }
}
