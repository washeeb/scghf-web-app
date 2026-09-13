<?php

declare(strict_types=1);

namespace App\Filament\Resources\FailedJobs\Schemas;

use Filament\Schemas\Schema;

/** A failed job is retried or discarded, never edited. */
class FailedJobForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([]);
    }
}
