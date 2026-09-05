<?php

declare(strict_types=1);

namespace App\Media;

use Imagick;
use Throwable;

/**
 * What this particular server can actually do to an image.
 *
 * ── Why this exists rather than a config value ──────────────────────────────
 *
 * The master prompt asks for image handling that "works without ImageMagick if
 * the host lacks it — detect and degrade gracefully". Degrading gracefully
 * requires knowing, and on shared hosting nobody knows: the account is
 * provisioned by somebody else, the PHP build changes when the host upgrades a
 * server, and the answer is different on the developer's laptop from the answer
 * on the account the site actually runs on.
 *
 * A config flag saying "we have WebP" is a belief. This asks.
 *
 * ── The three things that are usually missing ───────────────────────────────
 *
 * IMAGICK. Frequently absent on shared hosting. GD is always there, handles
 * JPEG/PNG/WebP fine, and is what this project already uses for metadata
 * stripping — so its absence costs quality at the margins, not function.
 *
 * exec(). Very frequently in `disable_functions`, and this is the quiet one:
 * spatie's image optimisers are external BINARIES (jpegoptim, pngquant, cwebp).
 * With exec disabled they do not error — the optimizer chain runs, every binary
 * fails to launch, and the library reports success having optimised nothing.
 * Files come out 30% larger than expected and nothing anywhere says why. That
 * is worth detecting precisely because it is invisible.
 *
 * AVIF. Needs PHP 8.1+ AND a GD or Imagick compiled with libavif, which shared
 * hosting rarely has. Encoding it is also seconds rather than milliseconds per
 * image, so it stays off by default even where it is available.
 */
class ImageToolchain
{
    /** @var array<string, mixed>|null */
    private ?array $report = null;

    // ── Drivers ──────────────────────────────────────────────────────────────

    public function hasImagick(): bool
    {
        return extension_loaded('imagick') && class_exists(Imagick::class);
    }

    public function hasGd(): bool
    {
        return extension_loaded('gd') && function_exists('imagecreatetruecolor');
    }

    /**
     * The driver spatie should be told to use.
     *
     * Imagick when present: better resampling, more formats, and it does not
     * hold the whole decompressed bitmap in PHP's memory_limit the way GD does
     * — which on a shared account with a 128MB limit is the difference between
     * a 6000px photograph converting and a white screen.
     */
    public function preferredDriver(): string
    {
        return $this->hasImagick() ? 'imagick' : 'gd';
    }

    /**
     * Whether anything at all can resize an image.
     *
     * False means conversions are impossible and originals are served as-is.
     * The site still works; it just serves a 4MB photograph to a phone, which
     * is a performance problem rather than a broken page — so this degrades
     * rather than throwing.
     */
    public function canResize(): bool
    {
        return $this->hasImagick() || $this->hasGd();
    }

    // ── Formats ──────────────────────────────────────────────────────────────

    public function canEncodeWebp(): bool
    {
        if ($this->hasImagick()) {
            return $this->imagickSupports('WEBP');
        }

        return $this->hasGd()
            && function_exists('imagewebp')
            && (bool) (gd_info()['WebP Support'] ?? false);
    }

    public function canEncodeAvif(): bool
    {
        if ($this->hasImagick()) {
            return $this->imagickSupports('AVIF');
        }

        return $this->hasGd()
            && function_exists('imageavif')
            && (bool) (gd_info()['AVIF Support'] ?? false);
    }

    /**
     * The formats conversions will actually be written in.
     *
     * Config asks; this answers. A config that says `avif => true` on a host
     * that cannot encode AVIF must not produce a queue full of failing jobs, so
     * the intersection is what runs.
     *
     * @return array<int, string>
     */
    public function outputFormats(): array
    {
        $formats = [];

        if (config('media.formats.webp', true) && $this->canEncodeWebp()) {
            $formats[] = 'webp';
        }

        if (config('media.formats.avif', false) && $this->canEncodeAvif()) {
            $formats[] = 'avif';
        }

        return $formats;
    }

    // ── Optimisers ───────────────────────────────────────────────────────────

    /**
     * Whether PHP may launch an external process at all.
     *
     * Checked before looking for any binary, because on a host with exec
     * disabled the answer for every binary is no regardless of whether it is
     * installed — and "jpegoptim not found" would send somebody looking for the
     * wrong problem.
     */
    public function canRunExternalBinaries(): bool
    {
        if (! function_exists('exec') || ! function_exists('proc_open')) {
            return false;
        }

        $disabled = array_map(
            static fn (string $fn): string => trim(strtolower($fn)),
            explode(',', (string) ini_get('disable_functions')),
        );

        return ! array_intersect(['exec', 'proc_open'], $disabled);
    }

    /**
     * Which of spatie's optimiser binaries are present and runnable.
     *
     * @return array<string, bool>
     */
    public function optimisers(): array
    {
        $binaries = ['jpegoptim', 'optipng', 'pngquant', 'svgo', 'gifsicle', 'cwebp', 'avifenc'];

        if (! $this->canRunExternalBinaries()) {
            return array_fill_keys($binaries, false);
        }

        $found = [];

        foreach ($binaries as $binary) {
            $found[$binary] = $this->binaryExists($binary);
        }

        return $found;
    }

    // ── The whole picture ────────────────────────────────────────────────────

    /**
     * Everything, for `scghf:media-doctor` and the admin diagnostics page.
     *
     * Memoised per instance: `binaryExists()` shells out once per binary, which
     * is cheap once and silly seven times.
     *
     * @return array<string, mixed>
     */
    public function report(): array
    {
        return $this->report ??= [
            'driver' => [
                'imagick' => $this->hasImagick(),
                'imagick_version' => $this->imagickVersion(),
                'gd' => $this->hasGd(),
                'preferred' => $this->preferredDriver(),
                'configured' => (string) config('media-library.image_driver', 'gd'),
            ],
            'formats' => [
                'webp' => $this->canEncodeWebp(),
                'avif' => $this->canEncodeAvif(),
                'will_write' => $this->outputFormats(),
            ],
            'optimisers' => [
                'can_run_binaries' => $this->canRunExternalBinaries(),
                'found' => $this->optimisers(),
            ],
            'php' => [
                // The real ceiling on an upload, and almost always lower than
                // anything config/media.php says.
                'upload_max_filesize' => (string) ini_get('upload_max_filesize'),
                'post_max_size' => (string) ini_get('post_max_size'),
                'memory_limit' => (string) ini_get('memory_limit'),
                'max_execution_time' => (string) ini_get('max_execution_time'),
                'exif' => extension_loaded('exif'),
                'fileinfo' => extension_loaded('fileinfo'),
            ],
        ];
    }

    /**
     * The problems worth acting on, in plain sentences.
     *
     * Ordered by consequence, not by category — somebody reading this on a
     * deploy day needs the thing that breaks a donor's page load first, and
     * the thing that costs a few kilobytes last.
     *
     * @return array<int, string>
     */
    public function warnings(): array
    {
        $warnings = [];

        if (! $this->canResize()) {
            $warnings[] = 'Neither Imagick nor GD is available, so no conversions can be made at all. '
                .'Every image will be served at full size — a 4MB photograph to a phone on 3G.';
        }

        if (! extension_loaded('fileinfo')) {
            $warnings[] = 'The fileinfo extension is missing, so uploads cannot be checked against '
                .'their actual bytes. Upload validation refuses everything rather than trusting the '
                .'browser, so uploading will not work until this is enabled.';
        }

        if (! $this->canEncodeWebp() && config('media.formats.webp', true)) {
            $warnings[] = 'WebP encoding is not available, so conversions fall back to the original '
                .'format. Expect roughly a third more bytes on every image.';
        }

        if (config('media.formats.avif', false) && ! $this->canEncodeAvif()) {
            $warnings[] = 'MEDIA_AVIF is on but this server cannot encode AVIF. Nothing will break — '
                .'AVIF is simply skipped — but the setting is not doing anything.';
        }

        if (! $this->canRunExternalBinaries()) {
            $warnings[] = 'PHP cannot launch external processes (exec/proc_open are disabled), so '
                .'spatie\'s image optimisers cannot run. They fail SILENTLY rather than erroring, so '
                .'images will simply be larger than expected with nothing reporting it.';
        } else {
            $missing = array_keys(array_filter($this->optimisers(), static fn (bool $found): bool => ! $found));

            if ($missing !== []) {
                $warnings[] = 'These optimiser binaries are not installed: '.implode(', ', $missing).'. '
                    .'Conversions still work; the files are just bigger than they need to be.';
            }
        }

        if (! extension_loaded('exif')) {
            $warnings[] = 'The exif extension is missing. Metadata is still stripped by re-encoding, '
                .'but GPS data cannot be DETECTED — so `had_gps_data` will read false even for '
                .'photographs that arrived carrying coordinates, and the safeguarding signal is lost.';
        }

        return $warnings;
    }

    // ── Internals ────────────────────────────────────────────────────────────

    private function imagickSupports(string $format): bool
    {
        try {
            return Imagick::queryFormats(strtoupper($format)) !== [];
        } catch (Throwable) {
            return false;
        }
    }

    private function imagickVersion(): ?string
    {
        if (! $this->hasImagick()) {
            return null;
        }

        try {
            /** @var array{versionString?: string} $version */
            $version = Imagick::getVersion();

            return $version['versionString'] ?? null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Whether a binary is on the PATH.
     *
     * `command -v` rather than `which`: `which` is not installed everywhere and
     * its exit codes differ between implementations, whereas `command -v` is
     * POSIX shell built in. Wrapped because a host can disable exec between the
     * check above and this call, and a diagnostics tool must never be the thing
     * that throws.
     */
    private function binaryExists(string $binary): bool
    {
        /*
         * Not on Windows.
         *
         * `command -v` is a POSIX shell builtin and cmd.exe has neither it nor
         * `2>/dev/null`, so every check would print "The system cannot find the
         * path specified" to the console and then answer no anyway. This project
         * deploys to Linux; Windows is where it is developed, and a diagnostics
         * tool should be quiet about a platform it is not diagnosing.
         */
        if (PHP_OS_FAMILY === 'Windows') {
            return false;
        }

        try {
            $output = [];
            $status = 0;

            @exec('command -v '.escapeshellarg($binary).' 2>/dev/null', $output, $status);

            return $status === 0 && $output !== [];
        } catch (Throwable) {
            return false;
        }
    }
}
