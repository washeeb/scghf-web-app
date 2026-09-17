<?php

declare(strict_types=1);

namespace App\Models;

use App\Media\Exceptions\MediaInUse;
use App\Media\MediaUsage;
use App\Support\ImageSanitiser;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
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

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'depicts_people' => 'boolean',
            'depicts_children' => 'boolean',
            'withdrawn_at' => 'datetime',
            'metadata_stripped_at' => 'datetime',
            'had_gps_data' => 'boolean',
            'stripped_metadata_keys' => 'array',
        ]);
    }

    /**
     * A file in use cannot be deleted.
     *
     * ── Why this is in the model and not in a controller ────────────────────
     *
     * Because of what the schema does when it is not here. Thirty-four foreign
     * keys point at this table and thirty-two of them are ON DELETE SET NULL:
     * deleting a file that is in use does not fail, does not warn, and leaves
     * no trace. A donation receipt loses its PDF. A beneficiary loses their ID
     * document. A consent record loses the evidence it is evidence of, and
     * still reads to anybody auditing it later as a consent that has evidence.
     *
     * The other two keys are ON DELETE CASCADE and take the row with them.
     *
     * A guard in a Filament action would cover the Filament action. This covers
     * every path — a console command, a seeder, a bulk cleanup, an owner model
     * being deleted underneath it — because those are the paths nobody thinks
     * about when they write the cleanup script at eleven at night.
     *
     * ── There is deliberately no override ───────────────────────────────────
     *
     * Not a flag, not a `force` argument. The two things somebody actually
     * wants are to detach the file where it is used, or to REPLACE it and keep
     * every reference pointing at the same row — and `MediaLibrary::replace()`
     * exists for the second. An override would become the thing every caller
     * reaches for, and then this guard would protect nothing.
     *
     * The consequence worth knowing: deleting a folder full of in-use files
     * fails too, and that is the intended behaviour rather than an oversight.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $media): void {
            if ($media->isInUse()) {
                throw new MediaInUse(app(MediaUsage::class)->explain($media));
            }
        });
    }

    /** @return BelongsTo<MediaFolder, $this> */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(MediaFolder::class, 'folder_id');
    }

    // ── Use, and the refusal that depends on it ──────────────────────────────

    /**
     * Everywhere this file is referenced.
     *
     * @return array<int, array{table: string, column: string, label: string, id: int|string, title: ?string, confidential: bool}>
     */
    public function usages(): array
    {
        return app(MediaUsage::class)->for($this);
    }

    public function isInUse(): bool
    {
        return app(MediaUsage::class)->isInUse($this);
    }

    /**
     * Why this file cannot be deleted, or null if it can.
     *
     * @param  bool  $maySeeConfidential  whether the asker holds `beneficiaries.view`
     */
    public function deletionRejectionReason(bool $maySeeConfidential = false): ?string
    {
        if (! $this->isInUse()) {
            return null;
        }

        return app(MediaUsage::class)->explain($this, $maySeeConfidential)
            .' Remove it from those places first, or replace the file instead — replacing keeps '
            .'every reference pointing at it and updates them all at once.';
    }

    // ── Conversions ──────────────────────────────────────────────────────────

    /**
     * The URL for a conversion, falling back to the original.
     *
     * Spatie's own `getUrl('card')` throws `InvalidConversion` when the
     * conversion was never registered, and returns a URL to a file that is not
     * there when it was registered but has not been generated yet — which on
     * this host is the whole first minute after an upload, because conversions
     * run on the cron queue.
     *
     * Both cases are ordinary, and neither should be a broken image on a
     * donor's page. The original always exists, so it is the fallback.
     */
    public function conversionUrl(string $conversion): string
    {
        return $this->hasGeneratedConversion($conversion)
            ? $this->getUrl($conversion)
            : $this->getUrl();
    }

    public function hasGeneratedConversion(string $conversion): bool
    {
        return (bool) ($this->generated_conversions[$conversion] ?? false);
    }

    /** Recorded at upload; null for anything added before that, and for documents. */
    public function width(): ?int
    {
        $width = $this->getCustomProperty('width');

        return is_int($width) && $width > 0 ? $width : null;
    }

    public function height(): ?int
    {
        $height = $this->getCustomProperty('height');

        return is_int($height) && $height > 0 ? $height : null;
    }

    /**
     * How many files this row actually owns on disk.
     *
     * The original plus whatever conversions exist. Inodes are the scarce
     * resource on shared hosting and this is what `scghf:media-doctor` counts —
     * a library is the thing that exhausts an inode quota, and it does it
     * quietly, one upload at a time.
     */
    public function inodeCost(): int
    {
        return 1 + count(array_filter($this->generated_conversions ?? []));
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
        return $this->publicationRejectionReason() === null;
    }

    // ── People in the picture ────────────────────────────────────────────────

    /** @return MorphMany<Consent, $this> */
    public function consents(): MorphMany
    {
        return $this->morphMany(Consent::class, 'consentable');
    }

    /** @return BelongsTo<User, $this> */
    public function withdrawnBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'withdrawn_by');
    }

    public function isWithdrawn(): bool
    {
        return $this->withdrawn_at !== null;
    }

    /**
     * A valid, unrevoked, unexpired photo consent covering the website — and,
     * for a child, one a named parent or guardian gave.
     */
    public function hasValidPhotoConsent(): bool
    {
        // A query when the relation is not loaded: strict mode forbids a lazy
        // load, and a gallery of fifty portraits must not throw on the first.
        $consents = $this->relationLoaded('consents') ? $this->consents : $this->consents()->get();

        return $consents
            ->filter(fn (Consent $consent): bool => $consent->consent_type === Consent::TYPE_PHOTO
                && $consent->coversScope(Consent::SCOPE_WEBSITE)
                && $consent->isValid()
                && (! $this->depicts_children || ($consent->is_minor && filled($consent->guardian_name))))
            ->isNotEmpty();
    }

    /**
     * Take the image down everywhere it appears, now.
     *
     * Every render goes through `isPublishable()`, so this is enough: the
     * hero, the gallery, the card, the Open Graph image all stop showing it
     * on the next request. Nothing is deleted — the file, the record and
     * the reason stay, because "why did we take it down" is a question
     * somebody will ask.
     */
    public function withdraw(User $by, string $reason): void
    {
        if (trim($reason) === '') {
            throw new \RuntimeException('Withdrawing an image needs a reason. It is the one thing anybody will want to know later.');
        }

        $this->forceFill([
            'withdrawn_at' => now(),
            'withdrawn_reason' => $reason,
            'withdrawn_by' => $by->getKey(),
        ])->save();
    }

    public function reinstate(): void
    {
        $this->forceFill(['withdrawn_at' => null, 'withdrawn_reason' => null, 'withdrawn_by' => null])->save();
    }

    /**
     * Why this file may not be published, or null if it may.
     *
     * A sentence, so an editor is told what to do rather than told no.
     */
    public function publicationRejectionReason(): ?string
    {
        if ($this->isWithdrawn()) {
            return 'This image has been withdrawn'.($this->withdrawn_reason ? ': '.$this->withdrawn_reason : '.')
                .' It will not appear anywhere until it is reinstated.';
        }

        if ($this->depicts_people && ! $this->hasValidPhotoConsent()) {
            return $this->depicts_children
                ? 'This photograph shows a child and has no valid consent from a named parent or guardian. '
                    .'Record the consent on the Consent tab before it can be published.'
                : 'This photograph shows a person and has no valid photo consent recorded. Record it on '
                    .'the Consent tab before it can be published.';
        }

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
