<?php

declare(strict_types=1);

namespace App\Filament\Resources\Grants\RelationManagers;

use App\Models\GrantObligation;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;

/**
 * What the funder is owed, and by when. The scheduler reminds the grant's
 * owner a fortnight before each one and weekly after that until it is
 * marked done — so "done" is a button, not a note.
 */
class ObligationsRelationManager extends RelationManager
{
    protected static string $relationship = 'obligations';

    protected static ?string $title = 'Obligations';

    protected function getCreateAuthorizationResponse(): Response
    {
        return (auth()->user()?->can('grants.manage') ?? false) ? Response::allow() : Response::deny();
    }

    public function isReadOnly(): bool
    {
        return ! (auth()->user()?->can('grants.manage') ?? false);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->label(__('What is owed'))->required()->maxLength(191)
                ->helperText(__('"Six-month narrative report", "Audited accounts for 2026", "Receipt for tranche 2".')),
            Select::make('kind')->label(__('Kind'))->options(GrantObligation::KINDS)->default('report')->required(),
            DatePicker::make('due_on')->label(__('Due on'))->required(),
            Textarea::make('notes')->label(__('Notes'))->rows(2)->maxLength(1000),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('completedBy'))
            ->defaultSort('due_on')
            ->columns([
                TextColumn::make('title')->label(__('Owed'))->wrap()->description(fn (GrantObligation $record): ?string => $record->notes),
                TextColumn::make('kind')->label(__('Kind'))->badge()->formatStateUsing(fn (?string $state): string => GrantObligation::KINDS[$state] ?? (string) $state),
                TextColumn::make('due_on')->label(__('Due'))->date('j M Y')->sortable()
                    ->color(fn (GrantObligation $record): ?string => $record->isOverdue() ? 'danger' : ($record->completed_on === null && $record->due_on->lte(now()->addDays(14)) ? 'warning' : null)),
                TextColumn::make('completed_on')->label(__('Done'))->date('j M Y')->placeholder(__('Not yet'))
                    ->description(fn (GrantObligation $record): ?string => $record->completedBy?->name),
                TextColumn::make('reminded_at')->label(__('Last reminder'))->since()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                CreateAction::make()->label(__('Add an obligation')),
            ])
            ->recordActions([
                Action::make('complete')
                    ->label(__('Mark done'))
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (GrantObligation $record): bool => $record->completed_on === null && (auth()->user()?->can('grants.manage') ?? false))
                    ->requiresConfirmation()
                    ->action(fn (GrantObligation $record) => $record->complete(auth()->user())),
                EditAction::make()->visible(fn (GrantObligation $record): bool => $record->completed_on === null),
            ])
            ->toolbarActions([]);
    }
}
