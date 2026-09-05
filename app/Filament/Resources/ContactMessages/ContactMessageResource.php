<?php

declare(strict_types=1);

namespace App\Filament\Resources\ContactMessages;

use App\Filament\Resources\ContactMessages\Pages\EditContactMessage;
use App\Filament\Resources\ContactMessages\Pages\ListContactMessages;
use App\Filament\Resources\ContactMessages\Schemas\ContactMessageForm;
use App\Filament\Resources\ContactMessages\Tables\ContactMessagesTable;
use App\Models\ContactMessage;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class ContactMessageResource extends Resource
{
    protected static ?string $model = ContactMessage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInbox;

    protected static string|UnitEnum|null $navigationGroup = 'Inbox';

    protected static ?int $navigationSort = 10;

    protected static ?string $modelLabel = 'Message';

    protected static ?string $pluralModelLabel = 'Contact inbox';

    protected static ?string $recordTitleAttribute = 'subject';

    public static function form(Schema $schema): Schema
    {
        return ContactMessageForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ContactMessagesTable::configure($table);
    }

    /**
     * The inbox count, so somebody notices without opening it.
     *
     * New messages only. A badge counting everything would sit there at 400
     * within a year and be the same as no badge at all.
     */
    public static function getNavigationBadge(): ?string
    {
        $new = static::getEloquentQuery()->where('status', 'new')->count();

        return $new > 0 ? (string) $new : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    /**
     * Confidential departments are excluded unless the reader may see them.
     *
     * ⚠ The policy alone is not enough here.
     *
     * `ContactPolicy::view()` refuses an individual safeguarding message, which
     * covers opening one. It does not cover a LIST — a table query returns rows
     * without asking a policy about each, so without this the sender's name and
     * the subject line of a report about a child would appear in the general
     * inbox for anybody with `contact.view`, and only the message body would be
     * protected. The subject line of a safeguarding report is frequently the
     * whole disclosure.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (auth()->user()?->can('contact.view_safeguarding')) {
            return $query;
        }

        return $query->whereDoesntHave('department', fn (Builder $q) => $q->where('is_confidential', true));
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListContactMessages::route('/'),
            'edit' => EditContactMessage::route('/{record}/edit'),
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
