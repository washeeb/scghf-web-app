<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\Media;
use Illuminate\Support\Facades\Log;
use Spatie\MediaLibrary\MediaCollections\Events\MediaHasBeenAddedEvent;
use Throwable;

/**
 * Strips camera metadata the moment a file is added, before anybody can
 * download it.
 *
 * ── Why synchronously, on a host with a queue ───────────────────────────────
 *
 * The queue here runs from cron, once a minute, so a queued sanitiser leaves a
 * window of up to a minute in which the unsanitised original is on disk and
 * reachable. For most work that window is irrelevant. For a photograph carrying
 * a child's home coordinates it is the entire problem.
 *
 * Re-encoding a web-sized image costs tens of milliseconds, and it happens on an
 * admin upload rather than on a donor's page load. That is a cost worth paying
 * to close the window completely.
 *
 * ── A failure here does not fail the upload ─────────────────────────────────
 *
 * It is recorded on the row, and `Media::isPublishable()` refuses the file until
 * it is resolved. Losing the upload would tempt somebody to work around this;
 * refusing to publish it does not.
 */
class SanitiseUploadedImage
{
    public function handle(MediaHasBeenAddedEvent $event): void
    {
        $media = $event->media;

        if (! $media instanceof Media) {
            return;
        }

        try {
            $media->stripMetadata();

            if ($media->had_gps_data) {
                /*
                 * Worth saying out loud. One photograph with coordinates in it
                 * is a stripped file; a run of them means somebody is
                 * photographing beneficiaries on a phone with location services
                 * on, which is a conversation rather than a cleanup.
                 */
                Log::notice('An uploaded image carried location data, which has been removed.', [
                    'media' => $media->getKey(),
                    'file' => $media->file_name,
                ]);
            }
        } catch (Throwable $e) {
            Log::error('Could not sanitise an uploaded image; it cannot be published.', [
                'media' => $media->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
