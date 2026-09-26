<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Media\MediaLibrary;
use App\Models\Media;
use App\Models\MediaFolder;
use App\Support\Settings;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Put the foundation's logo files into the media library and point the
 * settings at them.
 *
 * ── The logo pack, as uploaded ──────────────────────────────────────────────
 *
 * Two files came from the foundation: the icon (the heart of two figures,
 * square) and the lockup (icon beside "St. Cecilia's GreaterHOPE
 * FOUNDATIONS", drawn for a light background). They live in
 * `resources/brand/` — a logo is part of the product in a way forty stock
 * photographs are not, so unlike the launch photography these are in the
 * repository. Two more are derived here from the lockup: a dark-background
 * version (the dark-green wordmark in white, the orange and the icon kept)
 * and a 1200×630 social card for links shared on WhatsApp and Facebook.
 *
 * ── Through the library, and then into the settings ─────────────────────────
 *
 * Every file goes through `MediaLibrary::add()` like a staff upload, so it
 * has alt text, a licence and conversions. Then each setting that shows a
 * logo — Header → Logo (light), Logo (dark), Logo (icon) and SEO → Social
 * image — is filled IF IT IS EMPTY. A logo the foundation has since replaced
 * in the panel is never overwritten; that is what makes this safe to run on
 * every deploy.
 *
 *     php artisan scghf:brand-assets            # import what is missing, fill empty settings
 *     php artisan scghf:brand-assets --check    # report only
 */
class BrandAssets extends Command
{
    protected $signature = 'scghf:brand-assets {--check : Report what is missing without importing anything}';

    protected $description = 'Import the foundation\'s logo files into the media library and point the header, PWA and social-image settings at them';

    public const FOLDER = 'Brand';

    /** The custom property that ties a library row to one of the assets below. */
    public const PROPERTY = 'brand_asset';

    /**
     * Each asset: the file in resources/brand, how it is described, and the
     * setting it fills when that setting is empty.
     *
     * `note` is provenance, kept on the row as a custom property. Not the
     * `credit` column: that renders under every photograph as "Photo: …",
     * which is right for a photographer and wrong under a logo.
     *
     * @var array<string, array{file: string, alt: string, note: string|null, setting: string}>
     */
    public const ASSETS = [
        'lockup-light' => [
            'file' => 'scghf-logo-lockup.png',
            'alt' => 'St. Cecilia\'s Greater Hope Foundations',
            'note' => null,
            'setting' => 'header.logo_light',
        ],
        'lockup-dark' => [
            'file' => 'scghf-logo-lockup-dark.png',
            'alt' => 'St. Cecilia\'s Greater Hope Foundations',
            'note' => 'Derived from the uploaded lockup: the green wordmark set in white for dark backgrounds.',
            'setting' => 'header.logo_dark',
        ],
        'icon' => [
            'file' => 'scghf-logo-icon.png',
            'alt' => 'St. Cecilia\'s Greater Hope Foundations logo',
            'note' => null,
            'setting' => 'header.logo_icon',
        ],
        'social-card' => [
            'file' => 'scghf-social-card.jpg',
            'alt' => 'St. Cecilia\'s Greater Hope Foundations',
            'note' => 'Derived from the uploaded lockup: the 1200×630 card shown when a link is shared.',
            'setting' => 'seo.og_image',
        ],
    ];

    public function handle(MediaLibrary $library, Settings $settings): int
    {
        $folder = $this->folder();
        $failed = 0;

        foreach (self::ASSETS as $key => $asset) {
            $media = self::asset($key);

            if ($media === null && ! $this->option('check')) {
                try {
                    $media = $this->import($library, $folder, $key, $asset);
                    $this->line(sprintf('  ✓ %-14s imported as %s', $key, $media->file_name));
                } catch (Throwable $e) {
                    $failed++;
                    $this->error(sprintf('  ✗ %-14s %s', $key, $e->getMessage()));

                    continue;
                }
            }

            $this->line(sprintf('  %s %-14s %s', $media ? '·' : '–', $key, $media ? 'in the library (#'.$media->getKey().')' : 'missing'));

            if ($media === null) {
                continue;
            }

            // A Media setting casts to an integer, so "empty" is 0 rather than null.
            $current = (int) ($settings->get($asset['setting']) ?: 0);

            if ($current > 0) {
                $this->line(sprintf('    %s already set (#%s), left alone', $asset['setting'], $current));
            } elseif ($this->option('check')) {
                $this->line(sprintf('    %s is empty; would be set to #%d', $asset['setting'], $media->getKey()));
            } else {
                $settings->set($asset['setting'], (string) $media->getKey());
                $this->line(sprintf('    %s set to #%d', $asset['setting'], $media->getKey()));
            }
        }

        $settings->flush();

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** The library row for one of the brand assets, if it has been imported. */
    public static function asset(string $key): ?Media
    {
        return Media::query()->where('custom_properties->'.self::PROPERTY, $key)->first();
    }

    private function folder(): MediaFolder
    {
        return MediaFolder::query()->firstOrCreate(
            ['slug' => 'brand', 'parent_id' => null],
            ['name' => self::FOLDER, 'description' => 'The logo pack. Replace a file here (Media → Replace file) and every place that shows it — header, receipts, emails, the app icon — updates at once.'],
        );
    }

    /** @param array{file: string, alt: string, note: string|null, setting: string} $asset */
    private function import(MediaLibrary $library, MediaFolder $folder, string $key, array $asset): Media
    {
        $source = resource_path('brand/'.$asset['file']);

        if (! File::exists($source)) {
            throw new \RuntimeException("No file at {$source}.");
        }

        // A copy, because the library MOVES the file it is given and the
        // original must stay in the repository for the next environment.
        $tmp = tempnam(sys_get_temp_dir(), 'scghf-brand-');
        File::copy($source, $tmp);

        try {
            $mime = str_ends_with($asset['file'], '.jpg') ? 'image/jpeg' : 'image/png';
            $file = new UploadedFile($tmp, $asset['file'], $mime, null, true);

            $media = $library->add($file, $folder, ['alt_text' => $asset['alt']]);
        } finally {
            @unlink($tmp);
        }

        $media->setCustomProperty(self::PROPERTY, $key);

        if ($asset['note'] !== null) {
            $media->setCustomProperty('note', $asset['note']);
        }

        $media->forceFill([
            'licence' => Media::LICENCE_OWN,
            'depicts_people' => false,
            'depicts_children' => false,
        ])->save();

        return $media->refresh();
    }
}
