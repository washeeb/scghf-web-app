<?php

declare(strict_types=1);

namespace App\Media\Concerns;

use App\Media\ImageToolchain;
use App\Models\Media as LibraryMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * The foundation's standard image conversions, on any model that owns media.
 *
 * ── Why a trait rather than a base class ────────────────────────────────────
 *
 * spatie decides which conversions to generate by asking `$media->model_type`
 * for them — so conversions belong to the OWNER, not to the media row. Any
 * model that holds media therefore has to declare the same three, and a trait
 * is the only way to say that once.
 *
 * `MediaFolder` uses it because a file uploaded to the library itself is owned
 * by its folder; that is what gives a plain library upload an owner able to
 * answer the conversion question at all.
 *
 * ── An unsanitised image gets NO conversions, deliberately ──────────────────
 *
 * This is the important part of the file.
 *
 * `SanitiseUploadedImage` runs on `MediaHasBeenAddedEvent`, which spatie fires
 * BEFORE `createDerivedFiles()` — so in the normal case conversions are cut
 * from an original whose camera metadata has already been removed, and they
 * inherit nothing.
 *
 * When sanitising FAILS, that ordering stops helping. `Media::isPublishable()`
 * refuses the original, so nobody can place it on a page — but conversions live
 * at derivable paths on a public disk, and generating them would put three more
 * copies of a photograph still carrying a child's home coordinates where a URL
 * can reach them.
 *
 * So: no conversions until the file is clean. Running
 * `scghf:regenerate-media-conversions` after fixing the cause produces them.
 */
trait HasLibraryMedia
{
    use InteractsWithMedia;

    public function registerMediaConversions(?Media $media = null): void
    {
        if (! $this->shouldConvert($media)) {
            return;
        }

        $toolchain = app(ImageToolchain::class);

        if (! $toolchain->canResize()) {
            // No Imagick and no GD. Originals are served as-is, which is slow
            // rather than broken — and registering conversions nothing can
            // perform would fill the queue with failures instead.
            return;
        }

        // The first available of the configured formats, or null to keep the
        // original's. `outputFormats()` is the intersection of what config asks
        // for and what this server can actually encode.
        $format = $toolchain->outputFormats()[0] ?? null;

        /** @var array<string, array{width: int, quality: int}> $conversions */
        $conversions = config('media.conversions', []);
        $originalWidth = $this->knownWidth($media);

        foreach ($conversions as $name => $spec) {
            /*
             * Never upscale. A 500px original asked for a 1600px "hero" gives a
             * file that is both larger and blurrier than the original — worse
             * on every axis, including the one it was meant to improve.
             *
             * `thumb` is exempt because it is a downscale by definition for
             * anything above the minimum dimension, and the admin grid needs
             * one for every image.
             */
            if ($originalWidth !== null && $name !== 'thumb' && $spec['width'] > $originalWidth) {
                continue;
            }

            $conversion = $this->addMediaConversion($name)
                ->width($spec['width'])
                ->quality($spec['quality'])
                /*
                 * Queued. On this host the queue is a cron worker, so the
                 * upload returns immediately and the conversions appear within
                 * the minute — which matters when somebody is adding thirty
                 * photographs from a project visit over a Ghanaian connection.
                 */
                ->queued();

            if ($format !== null) {
                $conversion->format($format);
            }

            /*
             * Skip the optimiser chain when this server cannot run it.
             *
             * spatie's optimisers are external binaries — jpegoptim, pngquant,
             * cwebp — and shared hosting very frequently has `exec` in
             * `disable_functions`. With it disabled the chain does not error:
             * it reads every converted file into memory to sniff its type,
             * shells out to a binary that cannot launch, and reports success
             * having optimised nothing.
             *
             * So on such a host it is pure cost — memory and time spent to
             * achieve exactly nothing — and skipping it is the "degrade
             * gracefully" half of detecting the toolchain in the first place.
             *
             * It also stops the memory climbing on a bulk import, which is how
             * this was found: a run of conversions in one process exhausted a
             * 128MB limit inside the optimiser's own `mime_content_type()`.
             */
            if (! $toolchain->canRunExternalBinaries()) {
                $conversion->nonOptimized();
            }
        }
    }

    /**
     * Whether this file is one conversions can be made from at all.
     */
    private function shouldConvert(?Media $media): bool
    {
        if ($media === null) {
            // Spatie also calls this with no media when building the set of
            // conversion NAMES. Registering them is correct there — the
            // per-file decisions below need a file.
            return true;
        }

        if (! str_starts_with((string) $media->mime_type, 'image/')) {
            return false;
        }

        /*
         * GIF keeps its original only. Every conversion path here produces a
         * single still frame, so converting an animated GIF silently turns it
         * into a picture of its first frame — which looks like a bug in the
         * upload rather than a decision.
         */
        if ($media->mime_type === 'image/gif') {
            return false;
        }

        // The safeguarding gate. See the class docblock.
        return $media instanceof LibraryMedia ? $media->hasBeenSanitised() : true;
    }

    /**
     * The original's width, if anything recorded it.
     *
     * Read from custom properties rather than measured, because this method
     * runs every time a conversion URL is resolved and `getimagesize()` is a
     * filesystem round trip. `MediaLibrary::add()` records it at upload.
     *
     * Null means unknown, and unknown registers every conversion — the
     * upscaling that might cause is a worse image, not a broken one, and
     * refusing to convert on missing metadata would be the more damaging guess.
     */
    private function knownWidth(?Media $media): ?int
    {
        if ($media === null) {
            return null;
        }

        $width = $media->getCustomProperty('width');

        return is_int($width) && $width > 0 ? $width : null;
    }
}
