<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmsBroadcasts;

use App\Filament\Resources\SmsBroadcasts\Pages\CreateSmsBroadcast;
use App\Filament\Resources\SmsBroadcasts\Pages\EditSmsBroadcast;
use App\Filament\Resources\SmsBroadcasts\Pages\ListSmsBroadcasts;
use App\Filament\Resources\SmsBroadcasts\Schemas\SmsBroadcastForm;
use App\Filament\Resources\SmsBroadcasts\Tables\SmsBroadcastsTable;
use App\Models\SmsBroadcast;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

/**
 * A text to many people: drafted by one person, costed on the screen,
 * approved by a second, queued into the ordinary outbox.
 */
class SmsBroadcastResource extends Resource
{
    protected static ?string $model = SmsBroadcast::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|UnitEnum|null $navigationGroup = 'Communications';

    protected static ?int $navigationSort = 50;

    protected static ?string $modelLabel = 'SMS broadcast';

    protected static ?string $pluralModelLabel = 'SMS broadcasts';

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return SmsBroadcastForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SmsBroadcastsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSmsBroadcasts::route('/'),
            'create' => CreateSmsBroadcast::route('/create'),
            'edit' => EditSmsBroadcast::route('/{record}/edit'),
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
