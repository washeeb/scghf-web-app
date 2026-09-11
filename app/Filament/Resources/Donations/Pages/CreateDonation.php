<?php

declare(strict_types=1);

namespace App\Filament\Resources\Donations\Pages;

use App\Filament\Resources\Donations\DonationResource;
use App\Models\Cause;
use App\Payments\OfflineDonationService;
use App\Support\AuditLogger;
use App\ValueObjects\Money;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Recording an offline gift.
 *
 * The form's data goes to `OfflineDonationService`, never to `Donation::create`
 * directly — that service allocates the gift to its appeal, snapshots the
 * deductibility, writes the offline transaction row, and receipts it, and a
 * cash gift typed into a plain form would skip all four.
 */
class CreateDonation extends CreateRecord
{
    protected static string $resource = DonationResource::class;

    protected static ?string $title = 'Record an offline gift';

    protected function handleRecordCreation(array $data): Model
    {
        $service = app(OfflineDonationService::class);

        $input = [
            'amount' => Money::ofMinor((int) $data['amount']),
            'cause' => filled($data['cause_id'] ?? null) ? Cause::find($data['cause_id']) : null,
            'offline_method' => $data['offline_method'],
            'offline_reference' => $data['offline_reference'] ?? null,
            'received_on' => $data['received_on'],
            'donor_name' => $data['donor_name'],
            'donor_email' => $data['donor_email'] ?? null,
            'donor_phone' => $data['donor_phone'] ?? null,
            'is_anonymous' => (bool) ($data['is_anonymous'] ?? false),
            'notes' => $data['notes'] ?? null,
        ];

        try {
            $donation = ($data['acknowledge'] ?? true) && filled($input['donor_email'])
                ? $service->recordAndAcknowledge($input, auth()->user())
                : $service->record($input, auth()->user());
        } catch (RuntimeException $e) {
            Notification::make()->title($e->getMessage())->danger()->persistent()->send();

            throw ValidationException::withMessages(['amount' => $e->getMessage()]);
        }

        app(AuditLogger::class)->record(
            'donation.recorded_offline',
            'Offline gift '.$donation->reference.' recorded ('.$donation->amount->format().')',
            $donation,
            auth()->user(),
            ['method' => $data['offline_method'], 'reference' => $donation->reference],
        );

        return $donation;
    }

    protected function getRedirectUrl(): string
    {
        return DonationResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
