<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmsLogs;

use App\Filament\Resources\SmsLogs\Pages\ListSmsLogs;
use App\Filament\Resources\SmsLogs\Pages\ViewSmsLog;
use App\Filament\Resources\SmsLogs\Schemas\SmsLogForm;
use App\Filament\Resources\SmsLogs\Schemas\SmsLogInfolist;
use App\Filament\Resources\SmsLogs\Tables\SmsLogsTable;
use App\Models\SmsLog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Every text the application sent, tried to send, or refused to send —
 * with what it cost.
 */
class SmsLogResource extends Resource
{
    protected static ?string $model = SmsLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleBottomCenterText;

    protected static string|UnitEnum|null $navigationGroup = 'Communications';

    protected static ?int $navigationSort = 90;

    protected static ?string $modelLabel = 'Text message';

    protected static ?string $pluralModelLabel = 'SMS log';

    protected static ?string $recordTitleAttribute = 'to_number';

    public static function form(Schema $schema): Schema
    {
        return SmsLogForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return SmsLogInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SmsLogsTable::configure($table);
    }

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
            'index' => ListSmsLogs::route('/'),
            'view' => ViewSmsLog::route('/{record}'),
        ];
    }
}
