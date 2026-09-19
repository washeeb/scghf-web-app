<?php

declare(strict_types=1);

namespace App\Filament\Resources\Beneficiaries\Pages;

use App\Beneficiaries\CaseAccess;
use App\Filament\Resources\Beneficiaries\BeneficiaryResource;
use App\Filament\Resources\Beneficiaries\Schemas\CaseSchema;
use App\Filament\Support\ExportAction;
use App\Models\Beneficiary;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;

/**
 * The list, with the one export in the system classified critical.
 *
 * Tier A columns only, the current filters only, the data-protection
 * lead only (`beneficiaries.export`, never by wildcard), recorded as
 * `beneficiaries.exported` with the row count and the filters that were on.
 */
class ListBeneficiaries extends ListRecords
{
    protected static string $resource = BeneficiaryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('New case')),
        ];
    }

    public function table(Table $table): Table
    {
        $table = parent::table($table);

        $user = auth()->user();

        if ($user !== null && app(CaseAccess::class)->canExport($user)) {
            $table->toolbarActions([
                ExportAction::make('beneficiaries.exported', __('beneficiary cases'), [
                    'Reference' => 'case_reference',
                    'Status' => fn (Beneficiary $record): string => CaseSchema::statusLabel((string) $record->status),
                    'Name' => 'full_name',
                    'Gender' => fn (Beneficiary $record): string => (string) $record->gender,
                    'Region' => fn (Beneficiary $record): string => (string) $record->region,
                    'District' => fn (Beneficiary $record): string => (string) $record->district,
                    'Division' => fn (Beneficiary $record): string => (string) $record->division?->name,
                    'Project' => fn (Beneficiary $record): string => (string) $record->project?->title,
                    'Worker' => fn (Beneficiary $record): string => (string) $record->caseWorker?->name,
                    'Submitted' => fn (Beneficiary $record): string => (string) $record->submitted_at?->format('Y-m-d'),
                    'Decided' => fn (Beneficiary $record): string => (string) $record->decided_at?->format('Y-m-d'),
                    'Closed' => fn (Beneficiary $record): string => (string) $record->closed_at?->format('Y-m-d'),
                    'Outcome' => fn (Beneficiary $record): string => (string) $record->outcome,
                ], ['division', 'project', 'caseWorker']),
            ]);
        }

        return $table;
    }
}
