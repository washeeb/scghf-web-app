<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmsTemplates;

use App\Filament\Resources\SmsTemplates\Pages\EditSmsTemplate;
use App\Filament\Resources\SmsTemplates\Pages\ListSmsTemplates;
use App\Filament\Resources\SmsTemplates\Schemas\SmsTemplateForm;
use App\Filament\Resources\SmsTemplates\Tables\SmsTemplatesTable;
use App\Models\SmsTemplate;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

/**
 * Every text the application sends. Measured on every save: a template
 * that grows past its segment budget is refused, because the third segment
 * is the one that doubles the bill.
 */
class SmsTemplateResource extends Resource
{
    protected static ?string $model = SmsTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDevicePhoneMobile;

    protected static string|UnitEnum|null $navigationGroup = 'Communications';

    protected static ?int $navigationSort = 20;

    protected static ?string $modelLabel = 'SMS template';

    protected static ?string $pluralModelLabel = 'SMS templates';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return SmsTemplateForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SmsTemplatesTable::configure($table);
    }

    /** Templates are created by the code that sends them, never typed in. */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSmsTemplates::route('/'),
            'edit' => EditSmsTemplate::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
