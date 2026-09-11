<?php

declare(strict_types=1);

namespace App\Filament\Resources\Donors\Pages;

use App\Filament\Concerns\AuditsRecordAccess;
use App\Filament\Resources\Donors\DonorResource;
use App\Models\Donor;
use App\Payments\DonorMerger;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Builder;

/**
 * One donor, and the one correction a donor record needs: merging in a
 * duplicate. The record being viewed is the one that survives.
 */
class ViewDonor extends ViewRecord
{
    protected static string $resource = DonorResource::class;

    use AuditsRecordAccess;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->auditAccess('donor.pii_viewed', 'Opened donor '.$this->getRecord()->getKey(), $this->getRecord());
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),

            Action::make('merge')
                ->label(__('Merge a duplicate into this donor'))
                ->icon('heroicon-o-arrows-pointing-in')
                ->color('warning')
                ->visible(fn (): bool => auth()->user()->can('donors.merge'))
                ->schema([
                    Select::make('duplicate_id')
                        ->label(__('Duplicate donor'))
                        ->helperText(__('Their gifts, regular gifts, pledges and sponsorships move to this record. Their contact details fill any blanks here and never overwrite what is here. The duplicate is then removed.'))
                        ->required()
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => Donor::query()
                            ->whereKeyNot($this->getRecord()->getKey())
                            ->where(fn (Builder $q) => $q
                                ->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%")
                                ->orWhere('phone', 'like', "%{$search}%"))
                            ->limit(20)
                            ->get()
                            ->mapWithKeys(fn (Donor $d): array => [$d->getKey() => static::describe($d)])
                            ->all())
                        ->getOptionLabelUsing(fn ($value): ?string => ($d = Donor::find($value)) ? static::describe($d) : null),
                ])
                ->requiresConfirmation()
                ->modalHeading(__('Merge donors'))
                ->modalDescription(__('This cannot be undone from the screen. Every gift is kept; only who it is filed under changes.'))
                ->action(function (array $data): void {
                    $duplicate = Donor::query()->findOrFail($data['duplicate_id']);

                    $moved = app(DonorMerger::class)->merge($this->getRecord(), $duplicate, auth()->user());

                    $this->getRecord()->refresh();

                    Notification::make()
                        ->title(__('Donors merged'))
                        ->body(__(':donations gifts and :subscriptions regular gifts moved.', [
                            'donations' => $moved['donations'] ?? 0,
                            'subscriptions' => $moved['subscriptions'] ?? 0,
                        ]))
                        ->success()
                        ->send();
                }),
        ];
    }

    private static function describe(Donor $donor): string
    {
        return collect([$donor->name, $donor->email, $donor->phone])->filter()->implode(' · ')
            .' — '.$donor->totalDonated()->format();
    }
}
