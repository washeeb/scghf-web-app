<?php

declare(strict_types=1);

namespace App\Filament\Resources\EmailLogs\Schemas;

use Filament\Schemas\Schema;

/** A log row is evidence; it is read, never edited. */
class EmailLogForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([]);
    }
}
