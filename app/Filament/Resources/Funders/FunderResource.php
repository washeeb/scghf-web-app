<?php

declare(strict_types=1);

namespace App\Filament\Resources\Funders;

use App\Filament\Resources\Funders\Pages\CreateFunder;
use App\Filament\Resources\Funders\Pages\EditFunder;
use App\Filament\Resources\Funders\Pages\ListFunders;
use App\Models\Funder;
use App\Models\Partner;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Who gives grants. Internal — never published; the public partners page
 * is its own table, and a funder that is also a partner links to it.
 */
class FunderResource extends Resource
{
    protected static ?string $model = Funder::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 62;

    protected static ?string $modelLabel = 'Funder';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('The funder'))->columns(2)->schema([
                TextInput::make('name')->label(__('Name'))->required()->maxLength(191),
                Select::make('funder_type')->label(__('Kind'))->options(Funder::TYPES)->default('foundation')->required(),
                TextInput::make('website_url')->label(__('Website'))->url()->maxLength(255),
                Select::make('partner_id')->label(__('Also a public partner'))
                    ->options(fn (): array => Partner::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->helperText(__('If their logo is on the partners page, link the two. Nothing here is published.')),
            ]),
            Section::make(__('Contact'))->columns(3)->schema([
                TextInput::make('contact_name')->label(__('Name'))->maxLength(191),
                TextInput::make('contact_email')->label(__('Email'))->email()->maxLength(191),
                TextInput::make('contact_phone')->label(__('Phone'))->maxLength(32),
            ]),
            Section::make(__('Notes'))->schema([
                Textarea::make('notes')->label(__('Notes'))->rows(4)->maxLength(5000)
                    ->helperText(__('What they fund, what they do not, who to write to, when their rounds open.')),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('grants'))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label(__('Funder'))->searchable()->sortable(),
                TextColumn::make('funder_type')->label(__('Kind'))->badge()->formatStateUsing(fn (?string $state): string => Funder::TYPES[$state] ?? (string) $state),
                TextColumn::make('contact_name')->label(__('Contact'))->placeholder('—')->description(fn (Funder $record): ?string => $record->contact_email),
                TextColumn::make('grants_count')->label(__('Grants'))->badge()->color('gray'),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFunders::route('/'),
            'create' => CreateFunder::route('/create'),
            'edit' => EditFunder::route('/{record}/edit'),
        ];
    }
}
