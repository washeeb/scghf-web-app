<?php

declare(strict_types=1);

namespace App\Filament\Resources\Documents\Tables;

use App\Filament\Resources\Documents\Schemas\DocumentForm;
use App\Models\Document;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class DocumentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('year', 'desc')
            ->columns([
                TextColumn::make('title')
                    ->label(__('Document'))
                    ->searchable()
                    ->description(fn (Document $record): string => DocumentForm::documentTypes()[$record->document_type]
                        ?? (string) $record->document_type),

                TextColumn::make('year')
                    ->label(__('Year'))
                    ->sortable()
                    ->placeholder('—'),

                TextColumn::make('download_count')
                    ->label(__('Downloads'))
                    ->alignEnd()
                    ->sortable(),

                IconColumn::make('requires_auth')
                    ->label(__('Sign-in'))
                    ->boolean()
                    ->toggleable(),

                IconColumn::make('is_published')
                    ->label(__('Shown'))
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('document_type')
                    ->label(__('Kind'))
                    ->options(DocumentForm::documentTypes()),

                TernaryFilter::make('is_published')->label(__('Shown on the site')),
                TernaryFilter::make('requires_auth')->label(__('Sign-in required')),
                TrashedFilter::make(),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ]);
    }
}
