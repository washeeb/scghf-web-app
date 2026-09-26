<?php

declare(strict_types=1);

namespace App\Media;

use App\Models\Media;
use App\Models\MediaFolder;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Http\UploadedFile;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Filesystem;
use Throwable;

/**
 * The one way a file gets into this application.
 *
 * The same argument as `MessageDispatcher`: a second upload path would be a
 * path with no MIME sniffing, no filename sanitising and no metadata stripping
 * on it — and the first thing to arrive through it would be a photograph of a
 * child carrying the coordinates of their home.
 *
 * ── What happens, in order ──────────────────────────────────────────────────
 *
 *   1. validate against UploadPolicy       — bytes, not the browser's claims
 *   2. resolve the folder                  — every library file has an owner
 *   3. hand to spatie, with a safe name
 *        ↳ spatie fires MediaHasBeenAddedEvent
 *        ↳ SanitiseUploadedImage strips metadata, synchronously
 *        ↳ spatie then generates conversions from the CLEAN original
 *   4. record the dimensions
 *
 * Step 3's ordering is spatie's, not ours, and it is the reason conversions
 * cannot inherit metadata the original arrived with. `HasLibraryMedia` covers
 * the case where step 3's sanitising fails.
 */
class MediaLibrary
{
    public function __construct(
        private readonly UploadPolicy $policy,
        private readonly MediaUsage $usage,
    ) {}

    /**
     * Put a file in the library.
     *
     * @param  array<string, mixed>  $attributes  alt_text, caption, credit, decorative
     *
     * @throws RuntimeException when the file is not acceptable
     */
    public function add(
        UploadedFile $file,
        ?MediaFolder $folder = null,
        array $attributes = [],
        ?User $actor = null,
        bool $private = false,
    ): Media {
        if ($reason = $this->policy->reject($file)) {
            throw new RuntimeException($reason);
        }

        $folder ??= $this->defaultFolder();
        $mime = (string) $this->policy->sniff($file);

        // Measured BEFORE the file moves: an UploadedFile's temporary path is
        // gone once spatie has taken it, and the dimensions are what stop a
        // small original being upscaled into a larger, blurrier "hero".
        $dimensions = str_starts_with($mime, 'image/')
            ? @getimagesize($file->getRealPath())
            : false;

        /** @var Media $media */
        $media = $folder
            ->addMedia($file->getRealPath())
            ->usingFileName($this->policy->safeFilename($file->getClientOriginalName(), $mime))
            // The human-readable name keeps the original stem, because
            // `DSC_0042` is what the person who took the photograph will search
            // for. Only the name ON DISK is sanitised.
            ->usingName($this->displayName($file))
            ->withCustomProperties(array_filter([
                'width' => $dimensions === false ? null : $dimensions[0],
                'height' => $dimensions === false ? null : $dimensions[1],
                'decorative' => ($attributes['decorative'] ?? false) === true ?: null,
            ], static fn (mixed $v): bool => $v !== null))
            // A private file — a paid download — lives on the `downloads`
            // disk, which nothing serves. It is still a library row, so the
            // usage report and the inode count know it exists.
            ->toMediaCollection($private ? 'private' : 'library', $private ? 'downloads' : '');

        $media->forceFill(array_filter([
            'folder_id' => $folder->getKey(),
            'alt_text' => $attributes['alt_text'] ?? null,
            'caption' => $attributes['caption'] ?? null,
            'credit' => $attributes['credit'] ?? null,
        ], static fn (mixed $v): bool => $v !== null))->save();

        return $media->refresh();
    }

    /**
     * Swap the file behind an existing media row, keeping its id.
     *
     * ── Why replacing beats deleting and re-uploading ───────────────────────
     *
     * Thirty-four foreign keys point at `media.id`. A new row means every one
     * of those references still points at the old file, so "replace the logo"
     * done as delete-and-upload leaves thirty places showing the old logo and
     * one showing the new one — and, because most of those keys are ON DELETE
     * SET NULL, some of them showing nothing at all.
     *
     * Keeping the id means every use updates at once, which is what somebody
     * asking to replace a file actually means.
     *
     * The new file must be the same KIND. Replacing a photograph with a PDF
     * would leave thirty places rendering an `<img>` at a document.
     */
    public function replace(Media $media, UploadedFile $file, ?User $actor = null): Media
    {
        if ($reason = $this->policy->reject($file)) {
            throw new RuntimeException($reason);
        }

        $mime = (string) $this->policy->sniff($file);
        $existingKind = $this->policy->kindFor((string) $media->mime_type);
        $incomingKind = $this->policy->kindFor($mime);

        if ($existingKind !== $incomingKind) {
            throw new RuntimeException(sprintf(
                'This file is a %s and the one it would replace is a %s. Everything already using '
                .'that file expects a %s, so replacing it this way would break those pages. Upload '
                .'it as a new file instead.',
                $incomingKind ?? 'file of an unknown kind',
                $existingKind ?? 'file of an unknown kind',
                $existingKind ?? 'file',
            ));
        }

        /*
         * Counted before anything moves, so the audit entry can say how far
         * this reaches. Afterwards is too late — the point of the number is
         * that the replacement changed all of them at once.
         */
        $usageCount = count($this->usage->for($media));

        $dimensions = str_starts_with($mime, 'image/') ? @getimagesize($file->getRealPath()) : false;
        $filesystem = app(Filesystem::class);

        // Everything belonging to the old file: the original, its conversions
        // and its responsive images. Left behind they would be orphans nothing
        // ever deletes — and on a host that counts inodes, orphans accumulate
        // into an outage.
        $filesystem->removeAllFiles($media);

        $media->forceFill([
            'file_name' => $this->policy->safeFilename($file->getClientOriginalName(), $mime),
            'mime_type' => $mime,
            'size' => (int) $file->getSize(),
            'manipulations' => [],
            'generated_conversions' => [],
            'responsive_images' => [],
            /*
             * The new file has not been looked at yet.
             *
             * Leaving the old timestamp would mean a fresh, unsanitised
             * photograph inheriting the previous file's clean bill of health —
             * and passing `isPublishable()` on the strength of a check that was
             * performed on a different image.
             */
            'metadata_stripped_at' => null,
            'had_gps_data' => false,
            'stripped_metadata_keys' => null,
            'sanitisation_error' => null,
        ])->save();

        if ($dimensions !== false) {
            $media->setCustomProperty('width', $dimensions[0]);
            $media->setCustomProperty('height', $dimensions[1]);
            $media->save();
        }

        /*
         * `Filesystem::add()` is the same call a fresh upload makes: it copies
         * the file in, fires MediaHasBeenAddedEvent — which is what runs the
         * sanitiser — and then generates the conversions from the result. Doing
         * the steps by hand here would be a second version of that sequence,
         * and the ordering is the whole safeguarding property.
         */
        $filesystem->add($file->getRealPath(), $media, $media->file_name);

        try {
            app(AuditLogger::class)->record(
                'media.replaced',
                sprintf(
                    'The file behind "%s" was replaced. It is used in %d %s, all of which now show '
                    .'the new file.',
                    $media->name,
                    $usageCount,
                    $usageCount === 1 ? 'place' : 'places',
                ),
                subject: $media,
                causer: $actor,
            );
        } catch (Throwable) {
            // Auditing must not undo a replacement that has already happened.
        }

        return $media->refresh();
    }

    /**
     * The folder a file goes into when nobody said.
     *
     * `Programmes` is the seeded general-purpose folder. Falling back to the
     * first folder rather than to null because a null owner means spatie cannot
     * find any conversions to generate — a file with no folder would silently
     * be a file with no thumbnail.
     */
    public function defaultFolder(): MediaFolder
    {
        return MediaFolder::firstWhere('path', '/programmes')
            ?? MediaFolder::orderBy('sort_order')->firstOrFail();
    }

    /**
     * The name a person sees, from the name they uploaded.
     *
     * Trimmed of its extension and length-capped, but otherwise left alone —
     * accents, capitals and spaces included. This never reaches the filesystem
     * or a URL, so none of the reasons to sanitise a filename apply to it, and
     * "Ama's graduation.jpg" is what somebody will search for.
     */
    private function displayName(UploadedFile $file): string
    {
        $name = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);

        return mb_substr(trim($name) === '' ? 'Untitled' : trim($name), 0, 191);
    }
}
