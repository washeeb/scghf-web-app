<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\MediaCollections\Models\Media as BaseMedia;

/**
 * Extends spatie Media so uploads can live in folders and carry alt text.
 *
 * Registered in config/media-library.php so the package returns this class.
 */
class Media extends BaseMedia
{
    /** @return BelongsTo<MediaFolder, $this> */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(MediaFolder::class, 'folder_id');
    }

    /**
     * Whether this file may be placed on a public page.
     *
     * Alt text is not decoration. Without it a screen-reader user gets a
     * filename, and WCAG 2.2 AA is a stated requirement of this project.
     */
    public function isPublishable(): bool
    {
        return filled($this->alt_text) || $this->isDecorative();
    }

    /** A purely decorative image is marked as such and gets alt="". */
    public function isDecorative(): bool
    {
        return $this->getCustomProperty('decorative', false) === true;
    }

    public function altText(): string
    {
        return $this->isDecorative() ? '' : (string) ($this->alt_text ?? '');
    }
}
