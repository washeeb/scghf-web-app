<?php

declare(strict_types=1);

namespace App\Filament\Resources\ScheduledMessages\Schemas;

use Filament\Schemas\Schema;

/** A queued message is cancelled, never edited. */
class ScheduledMessageForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([]);
    }
}
