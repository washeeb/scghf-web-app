<?php

declare(strict_types=1);

namespace App\Filament\Resources\Suppressions\Schemas;

use Filament\Schemas\Schema;

/** Suppressions are added and released by the table's actions, never edited. */
class SuppressionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([]);
    }
}
