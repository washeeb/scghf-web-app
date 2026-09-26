<?php

declare(strict_types=1);

namespace App\Filament\Resources\ChatConversations\Pages;

use App\Filament\Resources\ChatConversations\ChatConversationResource;
use Filament\Resources\Pages\ListRecords;

class ListChatConversations extends ListRecords
{
    protected static string $resource = ChatConversationResource::class;

    public function getSubheading(): ?string
    {
        return __('While this page or a chat is open, the site tells visitors the office is online.');
    }
}
