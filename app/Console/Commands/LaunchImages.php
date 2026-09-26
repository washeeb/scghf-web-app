<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Media\MediaLibrary;
use App\Models\Media;
use App\Models\MediaFolder;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Fetch the launch photography into the media library.
 *
 * ── Why a manifest and not files in the repository ──────────────────────────
 *
 * The site launches with licensed placeholder photographs — real pictures
 * of Ghanaian life from Unsplash, to be replaced one by one as the
 * foundation's own consented photography accumulates. Forty-odd JPEGs are
 * twenty megabytes; in git they would be in every clone and every deploy
 * forever, after the last of them had been replaced. So the repository
 * holds `database/seeders/launch-images.json` — each picture's source,
 * photographer, alt text and the slot it fills — and this command fetches
 * them into the library on whichever environment runs it.
 *
 * ── Through the library, not around it ──────────────────────────────────────
 *
 * Every picture goes through `MediaLibrary::add()`: the upload policy
 * (type, size, dimensions), metadata stripping, the responsive
 * conversions. Nothing is written to the disk that a staff upload could
 * not have written. Each row is marked `licence = stock` with the source
 * page, so the photo-consent gate — which exists for the foundation's own
 * photographs of people — knows the permission is the licence.
 *
 * Idempotent: a slot that already has a picture is left alone, so the
 * command can run on every deploy and a picture staff replaced stays
 * replaced.
 *
 *     php artisan scghf:launch-images            # fetch what is missing
 *     php artisan scghf:launch-images --check    # list what is missing, fetch nothing
 */
class LaunchImages extends Command
{
    protected $signature = 'scghf:launch-images {--check : Report what is missing without fetching anything}';

    protected $description = 'Fetch the launch photography listed in database/seeders/launch-images.json into the media library';

    public const FOLDER = 'Launch photography';

    public const PROPERTY = 'launch_slot';

    public function handle(MediaLibrary $library): int
    {
        $manifest = self::manifest();
        $folder = $this->folder();
        $missing = [];

        foreach ($manifest as $entry) {
            if (self::forSlot($entry['slot']) === null) {
                $missing[] = $entry;
            }
        }

        $this->line(sprintf('%d pictures in the manifest, %d already in the library, %d missing.', count($manifest), count($manifest) - count($missing), count($missing)));

        if ($this->option('check') || $missing === []) {
            foreach ($missing as $entry) {
                $this->line('  missing: '.$entry['slot']);
            }

            return self::SUCCESS;
        }

        $fetched = 0;
        $failed = 0;

        foreach ($missing as $entry) {
            try {
                $media = $this->fetch($library, $folder, $entry);
                $fetched++;
                $this->line(sprintf('  ✓ %-24s %s  (%s)', $entry['slot'], $media->file_name, $entry['photographer']));
            } catch (Throwable $e) {
                $failed++;
                $this->error(sprintf('  ✗ %-24s %s', $entry['slot'], $e->getMessage()));
            }
        }

        $this->line(sprintf('Fetched %d, failed %d.', $fetched, $failed));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<int, array{slot: string, unsplash_id: string, page: string, photographer: string, username: string, alt: string}> */
    public static function manifest(): array
    {
        $path = database_path('seeders/launch-images.json');

        if (! File::exists($path)) {
            throw new RuntimeException("No manifest at {$path}.");
        }

        /** @var array<int, array{slot: string, unsplash_id: string, page: string, photographer: string, username: string, alt: string}> $entries */
        $entries = json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR);

        return $entries;
    }

    /** The picture filling a slot, if it has been fetched (or staff put something there). */
    public static function forSlot(string $slot): ?Media
    {
        return Media::query()->where('custom_properties->'.self::PROPERTY, $slot)->first();
    }

    private function folder(): MediaFolder
    {
        return MediaFolder::query()->firstOrCreate(
            ['slug' => 'launch-photography', 'parent_id' => null],
            ['name' => self::FOLDER, 'description' => 'Licensed placeholder photography from the launch. Replace each picture with the foundation\'s own as it becomes available; the slot follows the picture.'],
        );
    }

    /** @param array{slot: string, unsplash_id: string, page: string, photographer: string, username: string, alt: string} $entry */
    private function fetch(MediaLibrary $library, MediaFolder $folder, array $entry): Media
    {
        // 2000px on the long side is enough for a full-width hero after the
        // library's own conversions; the original is never served as-is.
        $url = sprintf('https://images.unsplash.com/%s?w=2000&q=82&fm=jpg&fit=max', $entry['unsplash_id']);

        $response = Http::timeout(60)
            ->withUserAgent('SCGHF launch-images (+https://greaterhopefoundations.org)')
            ->get($url);

        if (! $response->successful()) {
            throw new RuntimeException("HTTP {$response->status()} from Unsplash.");
        }

        $tmp = tempnam(sys_get_temp_dir(), 'scghf-launch-');
        File::put($tmp, $response->body());

        try {
            $file = new UploadedFile($tmp, $entry['slot'].'.jpg', 'image/jpeg', null, true);

            $media = $library->add($file, $folder, [
                'alt_text' => $entry['alt'],
                'credit' => sprintf('%s, Unsplash', $entry['photographer']),
            ]);
        } finally {
            @unlink($tmp);
        }

        $media->setCustomProperty(self::PROPERTY, $entry['slot']);
        $media->forceFill([
            'licence' => Media::LICENCE_STOCK,
            'licence_url' => $entry['page'],
            // Honest about the content: these are pictures of people, and the
            // reason they may be shown is the licence, not a consent.
            'depicts_people' => true,
            'depicts_children' => str_contains(strtolower($entry['alt']), 'child') || str_contains(strtolower($entry['alt']), 'pupil') || str_contains(strtolower($entry['alt']), 'boy') || str_contains(strtolower($entry['alt']), 'girl') || str_contains(strtolower($entry['alt']), 'newborn'),
        ])->save();

        return $media->refresh();
    }
}
