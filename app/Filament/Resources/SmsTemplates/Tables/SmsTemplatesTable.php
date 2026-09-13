<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmsTemplates\Tables;

use App\Models\SmsTemplate;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SmsTemplatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('editor')->withCount('logs'))
            ->defaultSort('key')
            ->columns([
                TextColumn::make('name')->label(__('Template'))->searchable(['name', 'key', 'body'])
                    ->description(fn (SmsTemplate $r): string => $r->key),
                TextColumn::make('body')->label(__('Text'))->limit(70)->wrap(),
                TextColumn::make('estimated_segments')->label(__('Segments'))->alignEnd()
                    ->description(fn (SmsTemplate $r): string => (string) $r->encoding)
                    ->color(fn (SmsTemplate $r): string => (int) $r->estimated_segments > 1 ? 'warning' : 'gray'),
                TextColumn::make('category')->label(__('Category'))->badge(),
                TextColumn::make('logs_count')->label(__('Sent'))->alignEnd()->sortable(),
                IconColumn::make('is_active')->label(__('Sends'))->boolean(),
                TextColumn::make('updated_at')->label(__('Edited'))->since()->sortable()
                    ->description(fn (SmsTemplate $r): ?string => $r->editor?->name),
            ])
            ->filters([
                SelectFilter::make('category')->label(__('Category'))->options([
                    SmsTemplate::CATEGORY_TRANSACTIONAL => __('Transactional'),
                    SmsTemplate::CATEGORY_MARKETING => __('Marketing'),
                ]),
            ])
            ->recordActions([EditAction::make()]);
    }
}
