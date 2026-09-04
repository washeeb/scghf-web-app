<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\ImageSanitiser;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\MediaCollections\Models\Media as BaseMedia;

/**
 * Extends spatie Media so uploads can live in folders, carry alt text, and
 * prove they have had their metadata removed.
 *
 * Registered in config/media-library.php so the package returns this class.
 */
class Media extends BaseMedia
{
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'metadata_stripped_at' => 'datetime',
            'had_gps_data' => 'boolean',
            'stripped_metadata_keys' => 'array',
        ]);
    }

    /** @return BelongsTo<MediaFolder, $this> */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(MediaFolder::class, 'folder_id');
    }

    // ── Publication ──────────────────────────────────────────────────────────

    /**
     * Whether this file may be placed on a public page.
     *
     * Two gates, and both are refusals rather than warnings.
     *
     * ALT TEXT, because without it a screen-reader user gets a filename, and
     * WCAG 2.2 AA is a stated requirement of this project.
     *
     * METADATA, because an unsanitised photograph can carry the coordinates of
     * the place it was taken. For this foundation that place is frequently a
     * beneficiary's home, and the beneficiary is frequently a child. Publishing
     * the file publishes the address to anybody who downloads it and opens the
     * properties panel.
     */
    public function isPublishable(): bool
    {
        return $this->hasBeenSanitised() && ($this->isDecorative() || filled($this->alt_text));
    }

    /**
     * Why this file may not be published, or null if it may.
     *
     * A sentence, so an editor is told what to do rather than told no.
     */
    public function publicationRejectionReason(): ?string
    {
        if ($this->sanitisation_error !== null) {
            return 'This image could not have its metadata removed ('.$this->sanitisation_error.'), '
                .'so it cannot be published. An unsanitised photograph can carry the coordinates '
                .'of the place it was taken.';
        }

        if (! $this->hasBeenSanitised()) {
            return 'This image has not had its camera metadata removed yet. Photographs taken on '
                .'a phone routinely carry the exact location they were taken — which, here, is '
                .'often somebody\'s home.';
        }

        if (! $this->isDecorative() && blank($this->alt_text)) {
            return 'This image has no alt text. Without it a screen-reader user gets a filename.';
        }

        return null;
    }

    /**
     * Whether something has actually looked at this file.
     *
     * Null means NOT YET, and is read that way — which is correct for anything
     * uploaded before the sanitiser existed. Absence of evidence is not
     * evidence of absence, and here the difference is a child's address.
     */
    public function hasBeenSanitised(): bool
    {
        return $this->metadata_stripped_at !== null && $this->sanitisation_error === null;
    }

    /**
     * Not an image, so there is nothing a camera could have written into it.
     *
     * PDFs and documents are excluded from the gate because the sanitiser
     * cannot rewrite them — and pretending otherwise would be worse than saying
     * so. A PDF's own metadata is a separate problem, flagged rather than
     * silently claimed as solved.
     */
    public function isSanitisableImage(): bool
    {
        return in_array($this->mime_type, ['image/jpeg', 'image/png', 'image/webp'], true);
    }

    // ── Sanitising ───────────────────────────────────────────────────────────

    /**
     * Strip the metadata and record what happened.
     *
     * Idempotent: a file already sanitised and unchanged has nothing left to
     * remove, so a second run is a cheap no-op rather than a second re-encode.
     */
    public function stripMetadata(): void
    {
        if (! $this->isSanitisableImage()) {
            // Nothing this class can rewrite. Recorded as looked-at so the
            // backfill does not examine it again on every run.
            $this->forceFill([
                'metadata_stripped_at' => now(),
                'had_gps_data' => false,
                'stripped_metadata_keys' => null,
                'sanitisation_error' => null,
            ])->save();

            return;
        }

        if (! config('system.media.strip_exif', true)) {
            /*
             * Switched off. Nothing is stripped and, crucially, nothing is
             * recorded as stripped — so `isPublishable()` keeps refusing the
             * file. Turning this off does not make publishing easier; it makes
             * it impossible, which is the intended behaviour. See the note in
             * config/system.php.
             */
            return;
        }

        $path = $this->getPath();
        $result = app(ImageSanitiser::class)->sanitise($path);

        $this->forceFill([
            // Null on failure. A file that could not be sanitised must not read
            // as sanitised, and `isPublishable()` refuses it either way.
            'metadata_stripped_at' => $result['stripped'] ? now() : null,
            'had_gps_data' => $result['had_gps'] || (bool) $this->had_gps_data,
            // KEY NAMES only. Storing the coordinates we removed, next to the
            // photograph, would defeat the entire exercise.
            'stripped_metadata_keys' => $result['keys'] === [] ? null : $result['keys'],
            'sanitisation_error' => $result['error'],
        ])->save();
    }

    /**
     * Whether the file on disk still carries metadata.
     *
     * Verification rather than trust: `metadata_stripped_at` records that the
     * sanitiser ran, and this checks whether it worked.
     */
    public function stillCarriesMetadata(): bool
    {
        return $this->isSanitisableImage()
            && app(ImageSanitiser::class)->hasMetadata($this->getPath());
    }

    // ── Alt text ─────────────────────────────────────────────────────────────

    /** A purely decorative image is marked as such and gets alt="". */
    public function isDecorative(): bool
    {
        return $this->getCustomProperty('decorative', false) === true;
    }

    public function altText(): string
    {
        return $this->isDecorative() ? '' : (string) ($this->alt_text ?? '');
    }

    /**
     * Images that have never been through the sanitiser.
     *
     * The backfill's work list, and the number the dashboard shows: "41 images
     * have not had their metadata checked" is a sentence somebody acts on.
     */
    #[Scope]
    protected function unsanitised(Builder $query): void
    {
        $query->whereNull('metadata_stripped_at')
            ->whereIn('mime_type', ['image/jpeg', 'image/png', 'image/webp']);
    }

    /**
     * Images that arrived carrying location data.
     *
     * A safeguarding signal in its own right, not just a cleanup list: a run of
     * these means somebody's phone is configured in a way that needs a
     * conversation, not only a stripped file.
     */
    #[Scope]
    protected function arrivedWithLocation(Builder $query): void
    {
        $query->where('had_gps_data', true);
    }
}
