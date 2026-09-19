<?php

declare(strict_types=1);

namespace App\Filament\Resources\Payouts\Schemas;

use App\Filament\Support\MoneyField;
use App\Models\Beneficiary;
use App\Models\Cause;
use App\Models\Division;
use App\Models\Grant;
use App\Models\Payout;
use App\Models\Project;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Raising a payout. The model insists on an attribution (division,
 * project or cause) and a positive amount; the form asks for the same.
 */
class PayoutForm
{
    public const CATEGORIES = [
        Payout::CATEGORY_SCHOOL_FEES => 'School fees',
        Payout::CATEGORY_MEDICAL => 'Medical',
        Payout::CATEGORY_FOOD => 'Food',
        Payout::CATEGORY_RENT => 'Rent',
        Payout::CATEGORY_STIPEND => 'Stipend',
        Payout::CATEGORY_SUPPLIER => 'Supplier',
        Payout::CATEGORY_TRANSPORT => 'Transport',
        Payout::CATEGORY_EQUIPMENT => 'Equipment',
        'other' => 'Other',
    ];

    public const METHODS = [
        'mobile_money' => 'Mobile Money',
        'bank_transfer' => 'Bank transfer',
        'cash' => 'Cash',
        'cheque' => 'Cheque',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('The payment'))->columns(2)->schema([
                TextInput::make('payee_name')->label(__('Paid to'))->required()->maxLength(191),
                TextInput::make('payee_reference')->label(__('Their account or MoMo number'))->maxLength(191)
                    ->helperText(__('Where the money goes. Kept with the payout, not shown in lists.')),
                MoneyField::make('amount')->label(__('Amount'))->required(),
                Select::make('category')->label(__('Category'))->options(self::CATEGORIES)->default('other')->required(),
                Select::make('method')->label(__('How'))->options(self::METHODS)->default('mobile_money')->required(),
                TextInput::make('momo_network')->label(__('MoMo network'))->maxLength(32),
                Textarea::make('purpose')->label(__('What for'))->required()->rows(2)->maxLength(500)->columnSpanFull()
                    ->helperText(__('One line the funder would accept: "School fees, 40 children, Bongo, term 2 2026".')),
            ]),

            Section::make(__('Charged to'))
                ->description(__('At least one of division, project or appeal — an unattributed payment cannot be reported to a funder. A grant and a case are optional.'))
                ->columns(2)
                ->schema([
                    Select::make('division_id')->label(__('Division'))->options(fn (): array => Division::query()->orderBy('name')->pluck('name', 'id')->all())->searchable(),
                    Select::make('project_id')->label(__('Project'))->options(fn (): array => Project::query()->orderBy('title')->pluck('title', 'id')->all())->searchable(),
                    Select::make('cause_id')->label(__('Appeal'))->options(fn (): array => Cause::query()->orderBy('title')->pluck('title', 'id')->all())->searchable(),
                    Select::make('grant_id')->label(__('Grant'))
                        ->options(fn (): array => Grant::query()->whereIn('status', [Grant::STATUS_AWARDED])->orderBy('title')->pluck('title', 'id')->all())
                        ->searchable()
                        ->default(fn (): ?int => request()->integer('grant') ?: null)
                        ->helperText(__('Only an awarded grant can be spent against.')),
                    Select::make('beneficiary_id')->label(__('Beneficiary case'))
                        ->options(fn (): array => Beneficiary::query()->where('status', Beneficiary::STATUS_APPROVED)->orderBy('case_reference')->pluck('case_reference', 'id')->all())
                        ->searchable()
                        ->visible(fn (): bool => auth()->user()?->can('beneficiaries.view') ?? false)
                        ->helperText(__('Only an approved case. The reference, not the name.')),
                    Textarea::make('notes')->label(__('Notes'))->rows(2)->maxLength(1000)->columnSpanFull(),
                ]),
        ]);
    }
}
