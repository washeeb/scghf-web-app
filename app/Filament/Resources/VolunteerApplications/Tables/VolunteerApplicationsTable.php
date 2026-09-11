<?php

declare(strict_types=1);

namespace App\Filament\Resources\VolunteerApplications\Tables;

use App\Models\VolunteerApplication;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Applications, those awaiting a decision first.
 *
 * No export. A list of applicants with their dates of birth, addresses and
 * disclosed convictions is not something that should leave the application
 * as a spreadsheet, and there is no door list here that needs it.
 */
class VolunteerApplicationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['opportunity', 'checks']))
            ->defaultSort('submitted_at', 'desc')
            ->columns([
                TextColumn::make('full_name')
                    ->label(__('Applicant'))
                    ->searchable()
                    ->description(fn (VolunteerApplication $record): string => $record->reference),

                TextColumn::make('opportunity.title')
                    ->label(__('Role'))
                    ->placeholder(__('General application'))
                    ->wrap(),

                TextColumn::make('submitted_at')
                    ->label(__('Applied'))
                    ->dateTime('j M Y')
                    ->placeholder(__('Not submitted'))
                    ->sortable(),

                TextColumn::make('checks')
                    ->label(__('Checks'))
                    ->state(fn (VolunteerApplication $record): string => $record->status === VolunteerApplication::STATUS_APPROVED
                        ? __('complete')
                        : trans_choice('{0}all done|{1}one outstanding|[2,*]:count outstanding', count($record->outstandingChecks()), ['count' => count($record->outstandingChecks())]))
                    ->color(fn (VolunteerApplication $record): string => match (true) {
                        $record->hasFailedCheck() => 'danger',
                        $record->outstandingChecks() === [] => 'success',
                        default => 'warning',
                    }),

                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        VolunteerApplication::STATUS_SUBMITTED => __('New'),
                        VolunteerApplication::STATUS_UNDER_REVIEW => __('Under review'),
                        VolunteerApplication::STATUS_APPROVED => __('Approved'),
                        VolunteerApplication::STATUS_DECLINED => __('Declined'),
                        VolunteerApplication::STATUS_WITHDRAWN => __('Withdrawn'),
                        default => __('Draft'),
                    })
                    ->color(fn (string $state): string => match ($state) {
                        VolunteerApplication::STATUS_SUBMITTED => 'info',
                        VolunteerApplication::STATUS_UNDER_REVIEW => 'warning',
                        VolunteerApplication::STATUS_APPROVED => 'success',
                        default => 'gray',
                    }),
            ])
            ->filters([
                Filter::make('awaiting')
                    ->label(__('Awaiting a decision'))
                    ->default()
                    ->query(fn (Builder $query) => $query->awaitingDecision()),
                SelectFilter::make('status')->label(__('Status'))->options([
                    VolunteerApplication::STATUS_SUBMITTED => __('New'),
                    VolunteerApplication::STATUS_UNDER_REVIEW => __('Under review'),
                    VolunteerApplication::STATUS_APPROVED => __('Approved'),
                    VolunteerApplication::STATUS_DECLINED => __('Declined'),
                    VolunteerApplication::STATUS_WITHDRAWN => __('Withdrawn'),
                ]),
                SelectFilter::make('volunteer_opportunity_id')->label(__('Role'))->relationship('opportunity', 'title'),
                TrashedFilter::make(),
            ])
            ->recordActions([ViewAction::make()]);
    }
}
