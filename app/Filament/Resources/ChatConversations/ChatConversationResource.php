<?php

declare(strict_types=1);

namespace App\Filament\Resources\ChatConversations;

use App\Filament\Resources\ChatConversations\Pages\ListChatConversations;
use App\Filament\Resources\ChatConversations\Pages\ViewChatConversation;
use App\Filament\Resources\ChatConversations\Tables\ChatConversationsTable;
use App\Models\ChatConversation;
use App\Support\Features;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * The live-chat inbox.
 *
 * A list of conversations, oldest-unanswered first, and a page per
 * conversation where the thread refreshes itself and the reply box sends.
 * Being on either page is what makes a member of staff "online" to the
 * widget — the pages touch presence on every refresh.
 */
class ChatConversationResource extends Resource
{
    protected static ?string $model = ChatConversation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|UnitEnum|null $navigationGroup = 'Inbox';

    protected static ?int $navigationSort = 5;

    protected static ?string $modelLabel = 'Chat';

    protected static ?string $pluralModelLabel = 'Live chat';

    protected static ?string $recordTitleAttribute = 'visitor_name';

    /** The module is off entirely without the feature flag. */
    public static function shouldRegisterNavigation(): bool
    {
        return app(Features::class)->enabled('live_chat') && parent::shouldRegisterNavigation();
    }

    public static function canAccess(): bool
    {
        return app(Features::class)->enabled('live_chat') && parent::canAccess();
    }

    /** @return array<int, string> */
    public static function getGloballySearchableAttributes(): array
    {
        return ['visitor_name', 'visitor_email'];
    }

    /** @return array<string, string|null> */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        if (! $record instanceof ChatConversation) {
            return [];
        }

        return ['Status' => $record->status, 'Last message' => $record->last_message_at?->diffForHumans()];
    }

    public static function table(Table $table): Table
    {
        return ChatConversationsTable::configure($table);
    }

    /** Conversations waiting for an answer, so somebody notices. */
    public static function getNavigationBadge(): ?string
    {
        $waiting = ChatConversation::query()->open()->unreadForStaff()->count();

        return $waiting > 0 ? (string) $waiting : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getPages(): array
    {
        return [
            'index' => ListChatConversations::route('/'),
            'view' => ViewChatConversation::route('/{record}'),
        ];
    }
}
