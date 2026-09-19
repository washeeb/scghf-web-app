<?php

declare(strict_types=1);

namespace App\Filament\Resources\Beneficiaries\Tables;

use App\Beneficiaries\CaseAccess;
use App\Filament\Resources\Beneficiaries\Schemas\CaseSchema;
use App\Models\Beneficiary;
use App\Models\Division;
use App\Models\Project;
use App\Models\ShippingZone;
use App\Models\User;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The case list — Tier A only.
 *
 * Reference, name, status, programme, worker, last activity. No contact
 * detail, no health, no money in the list: the list is what everybody with
 * `beneficiaries.view` sees, and it is enough to find a case and open it.
 * Finance sees only the cases there is something to pay on.
 *
 * No bulk actions — none are registered — and no trashed filter.
 */
class BeneficiariesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query): Builder {
                $query->with(['division', 'project', 'caseWorker']);

                $user = auth()->user();

                if ($user !== null && app(CaseAccess::class)->actor($user) === CaseAccess::FINANCE) {
                    $query->whereIn('status', [Beneficiary::STATUS_APPROVED, Beneficiary::STATUS_CLOSED]);
                }

                return $query;
            })
            ->defaultSort('last_activity_at', 'desc')
            ->columns([
                TextColumn::make('case_reference')
                    ->label(__('Reference'))
                    ->fontFamily('mono')
                    ->searchable()
                    ->copyable(),
                TextColumn::make('full_name')
                    ->label(__('Name'))
                    ->searchable()
                    ->sortable()
                    ->description(fn (Beneficiary $record): string => trim(($record->district ?? '').($record->region ? ', '.$record->region : ''), ', ')),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => CaseSchema::statusLabel((string) $state))
                    ->color(fn (?string $state): string => match ($state) {
                        Beneficiary::STATUS_APPROVED => 'success',
                        Beneficiary::STATUS_DECLINED, Beneficiary::STATUS_WITHDRAWN => 'danger',
                        Beneficiary::STATUS_CLOSED => 'gray',
                        Beneficiary::STATUS_UNDER_REVIEW => 'warning',
                        default => 'info',
                    }),
                TextColumn::make('project.title')
                    ->label(__('Project'))
                    ->placeholder('—')
                    ->wrap()
                    ->toggleable(),
                TextColumn::make('caseWorker.name')
                    ->label(__('Worker'))
                    ->placeholder(__('Unassigned')),
                TextColumn::make('last_activity_at')
                    ->label(__('Last activity'))
                    ->since()
                    ->sortable(),
                TextColumn::make('submitted_at')
                    ->label(__('Submitted'))
                    ->date('j M Y')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options(array_combine(
                        $statuses = [Beneficiary::STATUS_DRAFT, Beneficiary::STATUS_SUBMITTED, Beneficiary::STATUS_UNDER_REVIEW, Beneficiary::STATUS_APPROVED, Beneficiary::STATUS_DECLINED, Beneficiary::STATUS_WITHDRAWN, Beneficiary::STATUS_CLOSED],
                        array_map(fn (string $s): string => CaseSchema::statusLabel($s), $statuses),
                    )),
                Filter::make('open')
                    ->label(__('Open cases only'))
                    ->query(fn (Builder $query): Builder => $query->whereNotIn('status', [Beneficiary::STATUS_CLOSED, Beneficiary::STATUS_DECLINED, Beneficiary::STATUS_WITHDRAWN]))
                    ->default(),
                SelectFilter::make('division_id')
                    ->label(__('Division'))
                    ->options(fn (): array => Division::query()->orderBy('name')->pluck('name', 'id')->all()),
                SelectFilter::make('project_id')
                    ->label(__('Project'))
                    ->options(fn (): array => Project::query()->orderBy('title')->pluck('title', 'id')->all()),
                SelectFilter::make('case_worker_id')
                    ->label(__('Worker'))
                    ->options(fn (): array => User::query()->whereIn('id', Beneficiary::query()->whereNotNull('case_worker_id')->select('case_worker_id'))->orderBy('name')->pluck('name', 'id')->all()),
                SelectFilter::make('region')
                    ->label(__('Region'))
                    ->options(array_combine(ShippingZone::REGIONS, ShippingZone::REGIONS)),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([]);
    }
}
