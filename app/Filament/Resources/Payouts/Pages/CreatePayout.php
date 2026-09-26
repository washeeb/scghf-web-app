<?php

declare(strict_types=1);

namespace App\Filament\Resources\Payouts\Pages;

use App\Filament\Resources\Payouts\PayoutResource;
use App\Models\Payout;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use RuntimeException;

/**
 * A new payout is a draft raised by the person signed in; it is submitted
 * from its page. The model's own refusals (no attribution, no amount)
 * are shown as they are.
 */
class CreatePayout extends CreateRecord
{
    protected static string $resource = PayoutResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['status'] = Payout::STATUS_DRAFT;
        $data['requested_by'] = auth()->id();

        return $data;
    }

    protected function handleRecordCreation(array $data): Payout
    {
        try {
            $payout = new Payout;
            $payout->forceFill($data)->save();

            return $payout;
        } catch (RuntimeException $e) {
            Notification::make()->title($e->getMessage())->danger()->persistent()->send();
            $this->halt();
        }

        return new Payout;
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
