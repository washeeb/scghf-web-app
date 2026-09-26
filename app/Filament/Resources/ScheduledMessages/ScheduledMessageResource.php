<?php

declare(strict_types=1);

namespace App\Filament\Resources\ScheduledMessages;

use App\Filament\Resources\ScheduledMessages\Pages\ListScheduledMessages;
use App\Filament\Resources\ScheduledMessages\Schemas\ScheduledMessageForm;
use App\Filament\Resources\ScheduledMessages\Tables\ScheduledMessagesTable;
use App\Models\ScheduledMessage;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Every email and text waiting to go, and why it has not gone yet.
 */
class ScheduledMessageResource extends Resource
{
    protected static ?string $model = ScheduledMessage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxStack;

    protected static string|UnitEnum|null $navigationGroup = 'Communications';

    protected static ?int $navigationSort = 70;

    protected static ?string $modelLabel = 'Queued message';

    protected static ?string $pluralModelLabel = 'Outbox';

    protected static ?string $recordTitleAttribute = 'to_address';

    public static function form(Schema $schema): Schema
    {
        return ScheduledMessageForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ScheduledMessagesTable::configure($table);
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
            'index' => ListScheduledMessages::route('/'),
        ];
    }
}
