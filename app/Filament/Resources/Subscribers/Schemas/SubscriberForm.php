<?php

declare(strict_types=1);

namespace App\Filament\Resources\Subscribers\Schemas;

use Filament\Schemas\Schema;

/** Subscribers are never edited by hand; the list is the whole screen. */
class SubscriberForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([]);
    }
}
