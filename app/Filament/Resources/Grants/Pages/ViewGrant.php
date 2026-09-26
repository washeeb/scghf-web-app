<?php

declare(strict_types=1);

namespace App\Filament\Resources\Grants\Pages;

use App\Filament\Resources\Grants\GrantResource;
use App\Filament\Support\MoneyField;
use App\Models\Grant;
use App\ValueObjects\Money;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use RuntimeException;

/**
 * The pipeline as buttons: submit, award (with the figure), decline, close.
 * Each is the model's own method and stamps its date.
 */
class ViewGrant extends ViewRecord
{
    protected static string $resource = GrantResource::class;

    protected function getHeaderActions(): array
    {
        $grant = fn (): Grant => $this->grant();
        $manage = fn (): bool => auth()->user()?->can('grants.manage') ?? false;

        return [
            EditAction::make()->visible($manage),

            Action::make('submit')
                ->label(__('Mark submitted'))
                ->icon('heroicon-o-paper-airplane')
                ->visible(fn (): bool => $manage() && in_array($grant()->status, [Grant::STATUS_IDEA, Grant::STATUS_DRAFTING], true))
                ->requiresConfirmation()
                ->action(function () use ($grant): void {
                    $grant()->submit();
                    $this->reload();
                }),

            Action::make('drafting')
                ->label(__('Start drafting'))
                ->icon('heroicon-o-pencil')
                ->color('gray')
                ->visible(fn (): bool => $manage() && $grant()->status === Grant::STATUS_IDEA)
                ->action(function () use ($grant): void {
                    $grant()->forceFill(['status' => Grant::STATUS_DRAFTING])->save();
                    $this->reload();
                }),

            Action::make('award')
                ->label(__('Awarded'))
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (): bool => $manage() && $grant()->status === Grant::STATUS_SUBMITTED)
                ->schema([
                    MoneyField::make('amount')->label(__('Amount awarded'))->required()
                        ->default(fn (): ?string => $grant()->amount_requested?->toMajorString()),
                    DatePicker::make('starts_on')->label(__('Grant period starts'))->default(fn () => $grant()->starts_on),
                    DatePicker::make('ends_on')->label(__('Grant period ends'))->default(fn () => $grant()->ends_on),
                ])
                ->action(function (array $data) use ($grant): void {
                    try {
                        $grant()->award(Money::ofMinor((int) $data['amount']), $data['starts_on'] ?? null, $data['ends_on'] ?? null);
                    } catch (RuntimeException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()->title(__('Recorded. Add the funder’s reporting dates under Obligations.'))->success()->send();
                    $this->reload();
                }),

            Action::make('decline')
                ->label(__('Declined'))
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (): bool => $manage() && $grant()->status === Grant::STATUS_SUBMITTED)
                ->requiresConfirmation()
                ->action(function () use ($grant): void {
                    $grant()->decline();
                    $this->reload();
                }),

            Action::make('close')
                ->label(__('Close'))
                ->icon('heroicon-o-lock-closed')
                ->color('gray')
                ->visible(fn (): bool => $manage() && $grant()->status === Grant::STATUS_AWARDED)
                ->requiresConfirmation()
                ->modalDescription(__('The grant period is over and the last report has gone. The record stays.'))
                ->action(function () use ($grant): void {
                    $grant()->close();
                    $this->reload();
                }),
        ];
    }

    private function grant(): Grant
    {
        $record = $this->getRecord();

        if (! $record instanceof Grant) {
            throw new \LogicException('This page shows a grant.');
        }

        return $record;
    }

    private function reload(): void
    {
        $this->record = $this->grant()->fresh(['funder', 'project', 'owner', 'division']);
    }
}
