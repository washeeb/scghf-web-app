<?php

declare(strict_types=1);

namespace App\Media;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * What a file has to be before it is allowed onto this server.
 *
 * ── Everything here distrusts the browser ───────────────────────────────────
 *
 * An uploaded file arrives with three claims about what it is: the filename,
 * the extension, and the Content-Type header. All three are supplied by
 * whatever did the uploading, and whatever did the uploading is not always a
 * browser. Exactly one thing in an upload is evidence, and that is the bytes.
 *
 * So the type is SNIFFED with finfo, and the extension is then checked against
 * what the sniff found — in both directions. A `.jpg` whose bytes are a PHP
 * script is refused. A genuine JPEG named `.php` is also refused, because the
 * extension is what a misconfigured server dispatches on.
 *
 * ── The dot rule ────────────────────────────────────────────────────────────
 *
 * `invoice.php.jpg` is a file that some Apache configurations will execute,
 * because mod_mime can dispatch on any extension in the chain rather than the
 * last one. This project deploys to shared cPanel hosting where that
 * configuration is not ours to audit. So the stored name keeps NO dots at all
 * except the single extension this class derives from the sniffed type.
 *
 * ── What is refused outright ────────────────────────────────────────────────
 *
 * SVG. It is an XML document that may contain <script>, and it would be served
 * from the same origin as the admin panel — an uploaded SVG is stored XSS
 * against whoever opens it, holding the session of whoever opens it. Sanitising
 * SVG reliably is a game played forever against new parser quirks. Refusing it
 * is a game nobody has to keep playing.
 */
class UploadPolicy
{
    /**
     * Check a file, and say what is wrong in a sentence somebody can act on.
     *
     * Returns null when the file is acceptable. Every message is written for
     * the person uploading — an editor with a photograph, not a developer with
     * a stack trace.
     */
    public function reject(UploadedFile $file): ?string
    {
        if (! $file->isValid()) {
            /*
             * Almost always PHP's own upload limits rather than anything this
             * class controls, and the default message ("the file failed to
             * upload") sends people looking in the wrong place. On shared
             * hosting `upload_max_filesize` is frequently 2M.
             */
            return sprintf(
                'The upload did not complete. This is usually the server\'s own limit rather than '
                .'ours — it currently accepts files up to %s per upload.',
                (string) ini_get('upload_max_filesize'),
            );
        }

        if (! extension_loaded('fileinfo')) {
            /*
             * Refuse, rather than fall back to the browser's Content-Type.
             *
             * Falling back would mean the one server that cannot verify uploads
             * is the one server that accepts anything — which is precisely
             * backwards. `scghf:media-doctor` reports this.
             */
            return 'Uploads cannot be checked on this server because the fileinfo extension is '
                .'missing, so none are accepted. Ask the host to enable it.';
        }

        $mime = $this->sniff($file);

        if ($mime === null) {
            return 'The contents of this file could not be identified, so it has not been accepted.';
        }

        $kind = $this->kindFor($mime);

        if ($kind === null) {
            return sprintf(
                'Files of this type (%s) are not accepted. Images can be JPEG, PNG, WebP or GIF; '
                .'documents can be PDF, Word, Excel, CSV or plain text.',
                $mime,
            );
        }

        $extension = $this->claimedExtension($file);

        if (! $this->extensionMatches($mime, $extension)) {
            /*
             * The mismatch case, and the message says what was found rather
             * than only that something was wrong — because the innocent version
             * of this (a PNG someone renamed to .jpg) is common, and the person
             * needs to know which way round it is.
             */
            return sprintf(
                'This file is named .%s but its contents are %s. Rename it correctly and upload it '
                .'again — a file whose name disagrees with its contents is refused, because that '
                .'disagreement is how a script gets uploaded as a photograph.',
                $extension === '' ? '(none)' : $extension,
                $mime,
            );
        }

        $limit = (int) config("media.max_bytes.{$kind}", 8 * 1024 * 1024);

        if ($file->getSize() > $limit) {
            return sprintf(
                'This file is %s. The limit for %ss is %s.',
                $this->humanBytes((int) $file->getSize()),
                $kind,
                $this->humanBytes($limit),
            );
        }

        return $kind === 'image' ? $this->rejectImage($file) : null;
    }

    /**
     * Image-only checks, run after the type is known to be an image.
     */
    private function rejectImage(UploadedFile $file): ?string
    {
        $dimensions = @getimagesize($file->getRealPath());

        if ($dimensions === false) {
            /*
             * finfo said image, getimagesize disagrees. That combination is not
             * a corrupt file so much as a file trying to be two things, and it
             * is the shape of a polyglot — a valid image with a payload
             * appended, which some parsers read as an image and others execute.
             */
            return 'This file claims to be an image but cannot be read as one, so it has not been '
                .'accepted.';
        }

        [$width, $height] = $dimensions;
        $minimum = (int) config('media.min_dimension', 200);

        if ($width < $minimum && $height < $minimum) {
            return sprintf(
                'This image is only %d×%d pixels. Anything under %d on both sides is too small to '
                .'put on a page — it would be stretched into a smear.',
                $width,
                $height,
                $minimum,
            );
        }

        /*
         * A decompression bomb: small on disk, enormous in memory. A 64,000 ×
         * 64,000 PNG of one flat colour compresses to a few kilobytes and needs
         * roughly 16GB as a bitmap, so it passes the size limit and then takes
         * the whole PHP process down. Four bytes per pixel is GD's truecolour
         * cost.
         */
        $memoryNeeded = $width * $height * 4;
        $ceiling = $this->memoryLimitBytes();

        if ($ceiling > 0 && $memoryNeeded > $ceiling * 0.6) {
            return sprintf(
                'This image is %d×%d, which is too large for this server to process — it would need '
                .'about %s of memory to open. Please resize it to something under 4000 pixels wide.',
                $width,
                $height,
                $this->humanBytes($memoryNeeded),
            );
        }

        return null;
    }

    // ── Type resolution ──────────────────────────────────────────────────────

    /**
     * The MIME type according to the bytes.
     *
     * `getMimeType()` on an UploadedFile uses the finfo database against the
     * temporary file's contents — NOT the client's Content-Type, which is
     * `getClientMimeType()` and is never consulted anywhere in this class.
     */
    public function sniff(UploadedFile $file): ?string
    {
        $mime = $file->getMimeType();

        return $mime === null || $mime === '' ? null : strtolower($mime);
    }

    /** 'image', 'document', or null when the type is not accepted at all. */
    public function kindFor(string $mime): ?string
    {
        /** @var array<string, array<string, array<int, string>>> $accept */
        $accept = config('media.accept', []);

        foreach ($accept as $kind => $types) {
            if (array_key_exists($mime, $types)) {
                return $kind;
            }
        }

        return null;
    }

    /** Whether this extension is one the sniffed type is allowed to wear. */
    public function extensionMatches(string $mime, string $extension): bool
    {
        /** @var array<string, array<string, array<int, string>>> $accept */
        $accept = config('media.accept', []);

        foreach ($accept as $types) {
            if (isset($types[$mime])) {
                return in_array(strtolower($extension), $types[$mime], true);
            }
        }

        return false;
    }

    /**
     * The canonical extension for a sniffed type — the first one listed.
     *
     * The stored file gets this rather than whatever the upload was called, so
     * a JPEG is always `.jpg` and never `.jpeg`, `.JPG` or `.jpg.jpeg`.
     */
    public function canonicalExtension(string $mime): string
    {
        /** @var array<string, array<string, array<int, string>>> $accept */
        $accept = config('media.accept', []);

        foreach ($accept as $types) {
            if (isset($types[$mime])) {
                return $types[$mime][0];
            }
        }

        return 'bin';
    }

    private function claimedExtension(UploadedFile $file): string
    {
        // `getClientOriginalExtension()` is the LAST extension in the name, so
        // `x.php.jpg` reports `jpg`. That is fine here — the dot collapsing in
        // `safeFilename()` is what deals with the chain.
        return strtolower($file->getClientOriginalExtension());
    }

    // ── Filenames ────────────────────────────────────────────────────────────

    /**
     * A stored filename that is safe as a path segment and as part of a URL.
     *
     * Everything the original name contributes is its readable stem, and even
     * that is transliterated and stripped: a filename reaches the filesystem,
     * the database, a URL and eventually an HTML attribute, and each of those
     * has a different set of characters it treats as structure.
     *
     * The extension comes from the SNIFFED type, never from the upload — which
     * is what makes `invoice.php.jpg` land on disk as `invoice-php.jpg`.
     */
    public function safeFilename(string $original, string $mime): string
    {
        $extension = $this->canonicalExtension($mime);

        $stem = Str::of($original)
            // Kill the directory traversal before anything else looks at it.
            ->replace(['\\', '/'], '-')
            ->beforeLast('.')
            // ASCII first: a right-to-left override in a filename makes
            // `photo\u{202E}gpj.exe` render as `photo.exe...jpg` in a file
            // listing, which is a trick played on a person rather than a parser.
            ->ascii()
            ->lower()
            // Every remaining dot goes. The single extension is appended below,
            // so nothing on disk carries a second one.
            ->replace('.', '-')
            ->replaceMatches('/[^a-z0-9\-_]+/', '-')
            ->replaceMatches('/-+/', '-')
            ->trim('-_')
            ->limit(max(1, (int) config('media.filename.max_length', 120) - strlen($extension) - 1), '')
            ->toString();

        // Everything can legitimately strip to nothing — a file named `图片.jpg`
        // transliterates to something, but `___.jpg` does not. A name is
        // required, so one is made.
        if ($stem === '') {
            $stem = 'file-'.Str::lower(Str::random(8));
        }

        return $stem.'.'.$extension;
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** PHP's memory limit in bytes; 0 when unlimited. */
    private function memoryLimitBytes(): int
    {
        $limit = trim((string) ini_get('memory_limit'));

        if ($limit === '' || $limit === '-1') {
            return 0;
        }

        $unit = strtolower(substr($limit, -1));
        $value = (int) $limit;

        return match ($unit) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }

    private function humanBytes(int $bytes): string
    {
        return match (true) {
            $bytes >= 1024 * 1024 * 1024 => round($bytes / 1024 / 1024 / 1024, 1).'GB',
            $bytes >= 1024 * 1024 => round($bytes / 1024 / 1024, 1).'MB',
            $bytes >= 1024 => round($bytes / 1024).'KB',
            default => $bytes.' bytes',
        };
    }
}
