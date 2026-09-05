<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Media;
use Filament\Forms\Components\Select;

/**
 * Choosing a file from the library.
 *
 * ── Only publishable files are offered ──────────────────────────────────────
 *
 * `Media::isPublishable()` refuses anything without alt text or with its camera
 * metadata still on it. Offering a blocked file here would let an editor attach
 * it and then wonder why nothing rendered — the image component refuses it too,
 * silently, because a broken image on a donation page is worse than none.
 *
 * So the gate is applied at the point of choosing, where there is room to say
 * why. The helper text is not decoration: "I uploaded it and it is not in the
 * list" is the exact question this causes, and it deserves an answer on the
 * same screen rather than in a manual.
 *
 * ── One implementation, eight resources ─────────────────────────────────────
 *
 * `BlockFieldFactory` had this first, and blog posts, galleries, testimonials,
 * partners, team members, documents and announcements all need the same thing.
 * Eight copies of a safeguarding gate is eight chances for one of them to drift
 * into offering a photograph that still carries the coordinates it was taken at.
 */
class MediaPicker
{
    /** The image formats this site actually renders. */
    private const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    /** What a foundation publishes as a download: reports, policies, forms. */
    private const DOCUMENT_TYPES = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    public static function image(string $name): Select
    {
        return static::base($name)
            ->options(fn (): array => static::optionsFor(self::IMAGE_TYPES))
            ->helperText(__(
                'Only images that are ready to publish appear here. One is missing if it has no '
                .'alt text yet, or if its camera metadata has not been removed — the media '
                .'library says which, on the file itself.'
            ));
    }

    /**
     * A document.
     *
     * No `isPublishable()` filter: that check is about alt text and stripped
     * EXIF, and neither means anything for a PDF. A document's own gate is
     * `requires_auth` on the record, which decides whether it is served
     * publicly or through an authorised controller.
     */
    public static function document(string $name): Select
    {
        return static::base($name)
            ->options(fn (): array => Media::query()
                ->whereIn('mime_type', self::DOCUMENT_TYPES)
                ->orderByDesc('id')
                ->limit(200)
                ->pluck('name', 'id')
                ->all())
            ->helperText(__('Upload it to the media library first.'));
    }

    private static function base(string $name): Select
    {
        return Select::make($name)
            ->searchable()
            ->preload();
    }

    /**
     * @param  array<int, string>  $mimeTypes
     * @return array<int|string, string>
     */
    private static function optionsFor(array $mimeTypes): array
    {
        return Media::query()
            // Narrowed in SQL before `isPublishable()` runs in PHP: the check
            // needs the model, and a library of several thousand files should
            // not be hydrated to render one select.
            ->whereNotNull('metadata_stripped_at')
            ->whereNull('sanitisation_error')
            ->whereIn('mime_type', $mimeTypes)
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->filter(fn (Media $media): bool => $media->isPublishable())
            ->mapWithKeys(fn (Media $media): array => [$media->getKey() => $media->name])
            ->all();
    }
}
