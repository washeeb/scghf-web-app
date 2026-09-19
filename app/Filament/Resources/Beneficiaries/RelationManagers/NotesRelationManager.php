<?php

declare(strict_types=1);

namespace App\Filament\Resources\Beneficiaries\RelationManagers;

use App\Beneficiaries\CaseAccess;
use App\Models\Beneficiary;
use App\Models\BeneficiaryNote;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The case log. Read here; written by "Add a note" on the case page and by
 * every action on it. Nothing in this table can be edited or deleted —
 * the model throws if anything tries.
 *
 * Shown to whoever holds view B or U on the case: the people who work it
 * and the Auditor. A plain viewer sees that the case exists, not what was
 * written about the person.
 */
class NotesRelationManager extends RelationManager
{
    protected static string $relationship = 'notes';

    protected static ?string $title = 'Case log';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = auth()->user();

        return $user !== null && $ownerRecord instanceof Beneficiary && app(CaseAccess::class)->canNote($user, $ownerRecord);
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('author'))
            ->defaultSort('created_at', 'desc')
            ->paginated([25, 50])
            ->columns([
                TextColumn::make('created_at')->label(__('When'))->dateTime('j M Y, H:i')->sortable(),
                TextColumn::make('author.name')->label(__('Who'))->placeholder(__('System')),
                TextColumn::make('kind')->label(__('Kind'))->badge()->formatStateUsing(fn (?string $state): string => match ($state) {
                    BeneficiaryNote::KIND_STATUS => __('Status'),
                    BeneficiaryNote::KIND_CONSENT => __('Consent'),
                    BeneficiaryNote::KIND_DOCUMENT => __('Document'),
                    BeneficiaryNote::KIND_REVEAL => __('ID revealed'),
                    default => __('Note'),
                })->color(fn (?string $state): string => match ($state) {
                    BeneficiaryNote::KIND_REVEAL => 'warning',
                    BeneficiaryNote::KIND_NOTE => 'info',
                    default => 'gray',
                }),
                TextColumn::make('body')->label(__('Entry'))->wrap(),
            ])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
