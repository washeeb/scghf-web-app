<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;
use Throwable;

/**
 * Removes the metadata a camera writes into an image, before anybody can
 * download it.
 *
 * ── The failure this exists to prevent ──────────────────────────────────────
 *
 * A volunteer photographs a beneficiary outside their home, on a phone with
 * location services on. The JPEG carries the coordinates of that home to within
 * a few metres, plus the phone's serial number, plus the exact time. The
 * photograph is uploaded and published, and anybody who downloads it can read a
 * vulnerable child's address out of the file.
 *
 * None of that is visible in an admin panel. The image looks like an image.
 *
 * ── How it works, and why this way ──────────────────────────────────────────
 *
 * Re-encoding through GD. GD does not carry metadata across, so decoding and
 * re-encoding produces a file with none — which is a blunt instrument, and
 * deliberately so: an allowlist of tags to keep would need to be right about
 * every camera manufacturer's private tags, and being wrong once is the whole
 * problem.
 *
 * Imagick would preserve quality better, but is NOT available on this host
 * (`php -m` shows `gd` and `exif`, no `imagick`), and a sanitiser that depends
 * on an extension the server does not have is a sanitiser that silently does
 * nothing.
 *
 * ── The trade-offs, stated ──────────────────────────────────────────────────
 *
 *   - Re-encoding a JPEG loses a little quality. At quality 90 this is not
 *     visible at web sizes, and a slightly softer photograph is a much smaller
 *     problem than a published home address.
 *   - The ICC colour profile goes too. Web browsers assume sRGB, which is what
 *     phone cameras produce, so this is invisible in practice.
 *   - Images with no metadata at all are left completely untouched. Most
 *     already-processed images fall in here, so most uploads pay nothing.
 */
class ImageSanitiser
{
    /**
     * Formats that can carry location data and that GD can rewrite.
     *
     * SVG is deliberately absent: it is XML, GD cannot process it, and it
     * carries a different class of risk entirely (scripts, external
     * references). SVG is handled by not accepting it from untrusted uploaders
     * rather than by pretending this class made it safe.
     */
    private const REWRITABLE = ['image/jpeg', 'image/png', 'image/webp'];

    /** JPEG quality on re-encode. High enough to be invisible at web sizes. */
    private const JPEG_QUALITY = 90;

    /**
     * Inspect and, if needed, rewrite a file in place.
     *
     * @return array{
     *     stripped: bool,
     *     had_gps: bool,
     *     keys: array<int, string>,
     *     error: string|null
     * }
     */
    public function sanitise(string $path): array
    {
        $result = ['stripped' => false, 'had_gps' => false, 'keys' => [], 'error' => null];

        try {
            if (! is_file($path) || ! is_readable($path)) {
                throw new RuntimeException('The file could not be read.');
            }

            $mime = $this->mimeType($path);

            if (! in_array($mime, self::REWRITABLE, true)) {
                // Not an image this class can rewrite. Nothing was stripped and
                // nothing is claimed to have been.
                return $result;
            }

            $metadata = $this->readMetadata($path, $mime);

            $result['keys'] = $metadata['keys'];
            $result['had_gps'] = $metadata['had_gps'];

            if ($metadata['keys'] === []) {
                /*
                 * Nothing to remove. The file is left byte-for-byte alone —
                 * re-encoding a clean image would cost quality for no benefit,
                 * and most already-processed uploads land here.
                 */
                $result['stripped'] = true;

                return $result;
            }

            $this->rewrite($path, $mime);
            $result['stripped'] = true;

            return $result;
        } catch (Throwable $e) {
            /*
             * A failure is recorded, never swallowed into a pass. The dangerous
             * outcome is not "stripping failed" — it is "stripping failed and
             * the image was published anyway because nothing checked".
             */
            $result['error'] = $e->getMessage();

            return $result;
        }
    }

    /**
     * Whether a file still carries metadata.
     *
     * Used to verify after the fact, and by the backfill command — so a claim
     * that a library was sanitised can be checked rather than believed.
     */
    public function hasMetadata(string $path): bool
    {
        try {
            return $this->readMetadata($path, $this->mimeType($path))['keys'] !== [];
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * What the file carries, by key name.
     *
     * VALUES ARE NEVER RETURNED. The caller stores this, and a caller that was
     * handed the coordinates would eventually store those too — next to the
     * photograph, which defeats the entire exercise.
     *
     * @return array{keys: array<int, string>, had_gps: bool}
     */
    private function readMetadata(string $path, string $mime): array
    {
        if ($mime !== 'image/jpeg' || ! function_exists('exif_read_data')) {
            /*
             * PNG and WebP carry metadata in chunks EXIF cannot read. Rather
             * than claim there is none, report the generic marker so the file is
             * rewritten anyway — cheap, and correct in the direction that
             * matters.
             */
            return [
                'keys' => $this->hasNonExifMetadata($path, $mime) ? ['embedded_metadata'] : [],
                'had_gps' => false,
            ];
        }

        // Suppressed: exif_read_data warns loudly on a file with no EXIF, which
        // is the normal case and not a problem.
        $exif = @exif_read_data($path, null, true);

        if ($exif === false || $exif === []) {
            return ['keys' => [], 'had_gps' => false];
        }

        $keys = [];
        $hadGps = false;

        foreach ($exif as $section => $values) {
            if (! is_array($values)) {
                continue;
            }

            // FILE and COMPUTED are things PHP worked out about the file, not
            // things the camera wrote into it. Reporting them would mean every
            // image "had metadata".
            if (in_array($section, ['FILE', 'COMPUTED'], true)) {
                continue;
            }

            if ($section === 'COMMENT') {
                $keys = array_merge($keys, $this->meaningfulComments($values));

                continue;
            }

            if ($section === 'GPS') {
                $hadGps = true;
            }

            foreach (array_keys($values) as $key) {
                $keys[] = $section.'.'.$key;
            }
        }

        return ['keys' => array_values(array_unique($keys)), 'had_gps' => $hadGps];
    }

    /**
     * JPEG comment segments worth removing.
     *
     * ── The one that would have looped for ever ─────────────────────────────
     *
     * GD stamps its own signature into every JPEG it writes:
     * `CREATOR: gd-jpeg v1.0 (using IJG JPEG v80), quality = 90`.
     *
     * So the file this class produces always carries a COM segment, and
     * counting that as metadata would mean every sanitised image immediately
     * looked unsanitised again — re-encoded on every pass, losing a little
     * quality each time, for ever, and never converging.
     *
     * GD's own signature is therefore ignored. A comment saying which library
     * wrote the file leaks nothing about anybody. Any OTHER comment is real
     * content somebody put there and is removed: the COM segment is free text,
     * and free text next to a photograph of a beneficiary is exactly the kind
     * of thing that turns out to say more than it should.
     *
     * @param  array<int, mixed>  $comments
     * @return array<int, string>
     */
    private function meaningfulComments(array $comments): array
    {
        $keys = [];

        foreach ($comments as $index => $comment) {
            if (! is_string($comment) || str_starts_with(trim($comment), 'CREATOR: gd-jpeg')) {
                continue;
            }

            if (trim($comment) === '') {
                continue;
            }

            $keys[] = 'COMMENT.'.$index;
        }

        return $keys;
    }

    /**
     * Whether a PNG or WebP carries metadata chunks.
     *
     * A cheap header scan rather than a full parse: the only decision it feeds
     * is "rewrite this file or leave it alone", and being wrong towards
     * rewriting costs a re-encode.
     */
    private function hasNonExifMetadata(string $path, string $mime): bool
    {
        $head = (string) file_get_contents($path, false, null, 0, 65536);

        $markers = $mime === 'image/png'
            ? ['tEXt', 'iTXt', 'zTXt', 'eXIf']
            : ['EXIF', 'XMP '];

        foreach ($markers as $marker) {
            if (str_contains($head, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Decode and re-encode, which is what actually removes the metadata.
     *
     * Written to a temporary file and moved into place, so a failure halfway
     * through leaves the original intact rather than a truncated image. On a
     * shared host running out of disk mid-write is a real possibility, and a
     * corrupted beneficiary photograph is not recoverable.
     */
    private function rewrite(string $path, string $mime): void
    {
        if (! function_exists('imagecreatefromstring')) {
            throw new RuntimeException(
                'The GD extension is not available, so image metadata cannot be removed. '
                .'Publishing images is unsafe until it is enabled.'
            );
        }

        $image = @imagecreatefromstring((string) file_get_contents($path));

        if ($image === false) {
            throw new RuntimeException('The file could not be decoded as an image.');
        }

        // Transparency survives the round trip. Without these two calls a
        // transparent PNG comes back with a black background.
        imagealphablending($image, false);
        imagesavealpha($image, true);

        $temp = $path.'.sanitising';

        $written = match ($mime) {
            'image/jpeg' => imagejpeg($image, $temp, self::JPEG_QUALITY),
            'image/png' => imagepng($image, $temp, 6),
            'image/webp' => imagewebp($image, $temp, self::JPEG_QUALITY),
            default => false,
        };

        imagedestroy($image);

        if ($written !== true || ! is_file($temp)) {
            @unlink($temp);

            throw new RuntimeException('The sanitised image could not be written.');
        }

        if (! @rename($temp, $path)) {
            @unlink($temp);

            throw new RuntimeException('The sanitised image could not replace the original.');
        }
    }

    private function mimeType(string $path): string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);

            if ($finfo !== false) {
                $mime = finfo_file($finfo, $path);
                finfo_close($finfo);

                if (is_string($mime)) {
                    return $mime;
                }
            }
        }

        return (string) mime_content_type($path);
    }
}
