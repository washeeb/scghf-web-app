<?php

declare(strict_types=1);

namespace App\Filament\Resources\Beneficiaries\Pages;

use App\Beneficiaries\CaseAccess;
use App\Filament\Resources\Beneficiaries\BeneficiaryResource;
use App\Models\Beneficiary;
use App\Models\BeneficiaryNote;
use Filament\Resources\Pages\EditRecord;

/**
 * Editing a case: only the fields this person may change, nothing else on
 * the page — and nothing else in the Livewire state, which is serialised
 * into the HTML. A save writes a status-kind note naming the fields that
 * changed (never their values), so the log shows that the medical note was
 * edited on a date by a person without the log becoming a copy of it.
 *
 * No delete action. See the resource.
 */
class EditBeneficiary extends EditRecord
{
    protected static string $resource = BeneficiaryResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $user = auth()->user();
        /** @var Beneficiary $case */
        $case = $this->getRecord();

        $allowed = $user ? app(CaseAccess::class)->editableFields($user, $case) : [];

        return array_intersect_key($data, array_flip($allowed));
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $user = auth()->user();
        /** @var Beneficiary $case */
        $case = $this->getRecord();

        $allowed = $user ? app(CaseAccess::class)->editableFields($user, $case) : [];

        // Belt and braces: the form only has the allowed fields, but a
        // crafted request could carry more. Anything else is dropped.
        return array_intersect_key($data, array_flip($allowed));
    }

    protected function afterSave(): void
    {
        /** @var Beneficiary $case */
        $case = $this->getRecord();
        $changed = array_keys($case->getChanges());
        $changed = array_values(array_diff($changed, ['updated_at', 'last_activity_at', 'ghana_card_index', 'assistance_minor']));

        if ($changed === []) {
            return;
        }

        $case->note(__('Edited: :fields.', ['fields' => implode(', ', $changed)]), BeneficiaryNote::KIND_STATUS, auth()->user());
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
