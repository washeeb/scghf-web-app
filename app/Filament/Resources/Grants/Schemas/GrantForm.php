<?php

declare(strict_types=1);

namespace App\Filament\Resources\Grants\Schemas;

use App\Filament\Support\MoneyField;
use App\Models\Division;
use App\Models\Funder;
use App\Models\Grant;
use App\Models\Project;
use App\Models\User;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * A grant's details. The status is not a field: it moves through the
 * actions on the grant's page (submit, award, decline, close), each of
 * which stamps its date. The amount awarded is set by "Award".
 */
class GrantForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('The grant'))->columns(2)->schema([
                TextInput::make('title')->label(__('Title'))->required()->maxLength(191)->columnSpanFull()
                    ->helperText(__('As you would say it in a meeting: "DFID small grant for the Bongo school kits".')),
                Select::make('funder_id')
                    ->label(__('Funder'))
                    ->relationship('funder', 'name')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->createOptionForm([
                        TextInput::make('name')->label(__('Name'))->required()->maxLength(191),
                        Select::make('funder_type')->label(__('Kind'))->options(Funder::TYPES)->default('foundation')->required(),
                        TextInput::make('contact_name')->label(__('Contact'))->maxLength(191),
                        TextInput::make('contact_email')->label(__('Contact email'))->email()->maxLength(191),
                        TextInput::make('website_url')->label(__('Website'))->url()->maxLength(255),
                    ]),
                TextInput::make('funder_reference')->label(__('Funder’s reference'))->maxLength(191)
                    ->helperText(__('Their number for it, if they gave one.')),
                Select::make('project_id')->label(__('Project it funds'))
                    ->options(fn (): array => Project::query()->orderBy('title')->pluck('title', 'id')->all())
                    ->searchable()
                    ->helperText(__('Spend against the grant is what is paid out under this project and charged to the grant.')),
                Select::make('division_id')->label(__('Division'))
                    ->options(fn (): array => Division::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
                Select::make('owner_id')->label(__('Owner'))
                    ->options(fn (): array => User::query()->whereHas('roles')->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->default(fn (): ?int => auth()->id())
                    ->helperText(__('Who is reminded about deadlines.')),
                Toggle::make('is_restricted')->label(__('Restricted to its purpose'))->default(true)
                    ->helperText(__('Most grants are: the money may only be spent on what was applied for.')),
            ]),

            Section::make(__('Money and dates'))->columns(2)->schema([
                MoneyField::make('amount_requested')->label(__('Amount asked for')),
                MoneyField::make('amount_awarded')->label(__('Amount awarded'))
                    ->visible(fn (?Grant $record): bool => $record !== null && in_array($record->status, [Grant::STATUS_AWARDED, Grant::STATUS_CLOSED], true))
                    ->helperText(__('Set when the grant was awarded; change it here only if the letter said otherwise.')),
                DatePicker::make('deadline_on')->label(__('Application deadline'))
                    ->helperText(__('The date the funder must have it by.')),
                DatePicker::make('starts_on')->label(__('Grant period starts')),
                DatePicker::make('ends_on')->label(__('Grant period ends')),
            ]),

            Section::make(__('In writing'))->schema([
                Textarea::make('purpose')->label(__('What it is for'))->rows(3)->maxLength(2000),
                Textarea::make('notes')->label(__('Notes'))->rows(3)->maxLength(5000)
                    ->helperText(__('Internal. Conditions, who said what, what to remember next time.')),
            ]),
        ]);
    }
}
