<?php

declare(strict_types=1);

namespace App\Filament\Resources\Media\Pages;

use App\Filament\Resources\Media\MediaResource;
use App\Models\Media;
use Filament\Resources\Pages\EditRecord;

/**
 * Describing a file.
 *
 * No delete action in the header: deleting is on the list row, where the
 * refusal — and the explanation of what is using the file — has room to be
 * read. A delete button here would be one click from a form somebody opened to
 * fix a typo in a caption.
 */
class EditMedia extends EditRecord
{
    protected static string $resource = MediaResource::class;

    /**
     * `decorative` lives in `custom_properties`, not in a column.
     *
     * That is spatie's place for per-file flags and `Media::isDecorative()`
     * already reads it from there, so the form hydrates the toggle from it and
     * writes it back here rather than adding a column that would then have two
     * sources of truth.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var Media $record */
        $record = $this->getRecord();

        $record->setCustomProperty('decorative', (bool) ($this->data['decorative'] ?? false));
        $record->save();

        return $data;
    }
}
