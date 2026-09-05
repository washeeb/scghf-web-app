<?php

declare(strict_types=1);

namespace App\Filament\Resources\Media\Pages;

use App\Filament\Resources\Media\MediaResource;
use App\Filament\Resources\Media\Tables\MediaTable;
use Filament\Resources\Pages\ListRecords;

/**
 * The library.
 *
 * ── No Create action, and no Create page behind it ──────────────────────────
 *
 * You do not create a media row; you upload a file, and a row is what happens
 * next. A create form would let somebody produce a `media` record pointing at
 * nothing on disk — bypassing the only door in, which is `MediaLibrary::add()`
 * and is where the MIME sniffing, the filename sanitising and the metadata
 * stripping live.
 *
 * So the header carries an Upload action instead, and it hands its files to
 * that service.
 */
class ListMedia extends ListRecords
{
    protected static string $resource = MediaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            MediaTable::uploadAction(),
        ];
    }
}
