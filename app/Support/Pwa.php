<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Media;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The progressive web app: a manifest, icons, and a service worker — all of
 * it derived from the CMS, none of it hardcoded.
 *
 * ── What "offline" means here ───────────────────────────────────────────────
 *
 * A donor on a Ghanaian bus loses the connection between the appeal and the
 * donate button. Without this they see the browser's dinosaur; with it they
 * see the foundation's page saying "you are offline — here is the Mobile
 * Money number", which is a delayed gift instead of a lost one. That is the
 * whole feature. Nothing that takes money, holds a basket or belongs to a
 * signed-in person is ever served from the worker's cache: the same list
 * the page cache uses (config/performance.php) plus the admin path.
 *
 * ── Icons ───────────────────────────────────────────────────────────────────
 *
 * Android wants 192 and 512 px PNGs, maskable. They are rendered once from
 * the uploaded logo (Header → Logo) onto a brand-coloured square with the
 * safe-zone padding a maskable icon needs, and cached under a hash of the
 * logo and the colour, so a new logo is a new URL and the old icon is never
 * served stale. No logo yet: a brand-coloured tile, which is honest.
 *
 * Gated by FEATURE_PWA_OFFLINE. Off, no manifest is linked and the worker
 * is not registered; a worker registered earlier unregisters itself on the
 * next visit (the script says so).
 */
final class Pwa
{
    public const SIZES = [192, 512];

    public const CACHE_VERSION = 1;

    public function enabled(): bool
    {
        return app(Features::class)->enabled('pwa_offline');
    }

    /** @return array<string, mixed> */
    public function manifest(): array
    {
        $tokens = app(ThemeTokens::class);
        $name = (string) setting('general.short_name', config('app.name'));

        return [
            'id' => '/',
            'name' => $name,
            'short_name' => (string) (setting('general.wordmark') ?: mb_substr($name, 0, 12)),
            'description' => (string) setting('seo.default_description', ''),
            'start_url' => '/?utm_source=pwa&utm_medium=homescreen',
            'scope' => '/',
            'display' => 'standalone',
            'orientation' => 'portrait',
            'lang' => str_replace('_', '-', app()->getLocale()),
            'background_color' => $tokens->value('bg', 'light'),
            'theme_color' => $tokens->value('brand-primary', 'light'),
            'icons' => array_map(fn (int $size): array => [
                'src' => $this->iconUrl($size),
                'sizes' => "{$size}x{$size}",
                'type' => 'image/png',
                'purpose' => 'any maskable',
            ], self::SIZES),
            'shortcuts' => [
                ['name' => __('Give'), 'url' => '/donate?utm_source=pwa&utm_medium=shortcut', 'icons' => [['src' => $this->iconUrl(192), 'sizes' => '192x192']]],
                ['name' => __('Other ways to give'), 'url' => '/give?utm_source=pwa&utm_medium=shortcut'],
            ],
        ];
    }

    public function iconUrl(int $size): string
    {
        return route('pwa.icon', ['size' => $size, 'v' => $this->iconVersion()]);
    }

    /**
     * Renders (or serves from the cache) the icon PNG of one size.
     *
     * @return string binary PNG
     */
    public function icon(int $size): string
    {
        $path = "pwa/icon-{$size}-{$this->iconVersion()}.png";
        $disk = Storage::disk('local');

        if ($disk->exists($path)) {
            return (string) $disk->get($path);
        }

        $png = $this->renderIcon($size);
        $disk->put($path, $png);

        return $png;
    }

    /** What the icon is made from: a change to either is a new version. */
    public function iconVersion(): string
    {
        $logo = $this->logo();

        return substr(md5(($logo ? (string) $logo->id : 'none').'|'.($logo?->updated_at ? $logo->updated_at->timestamp : 0).'|'.app(ThemeTokens::class)->value('brand-primary', 'light').'|'.self::CACHE_VERSION), 0, 10);
    }

    /**
     * The service worker, as JavaScript, with the precache list and the
     * exclusions filled in from configuration.
     */
    public function serviceWorker(): string
    {
        $precache = array_values(array_unique(array_filter([
            '/offline',
            ...$this->builtAssets(),
            '/fonts/Inter-latin.woff2',
        ])));

        $never = array_values(array_unique(array_merge(
            (array) config('performance.page_cache.except', []),
            [trim((string) config('admin.path', 'admin'), '/').'/*', 'livewire/*', 'donate', 'search', 'screen/*', 'sw.js', 'manifest.webmanifest'],
        )));

        $version = 'scghf-v'.self::CACHE_VERSION.'-'.substr(md5(implode('|', $precache)), 0, 8);

        $template = (string) file_get_contents(resource_path('js/sw.template.js'));

        return strtr($template, [
            '__CACHE_NAME__' => json_encode($version, JSON_THROW_ON_ERROR),
            '__PRECACHE__' => json_encode($precache, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            '__NEVER_CACHE__' => json_encode($never, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            '__ENABLED__' => $this->enabled() ? 'true' : 'false',
        ]);
    }

    /** @return array<int, string> the hashed CSS and JS the layout loads */
    private function builtAssets(): array
    {
        try {
            $manifest = public_path('build/manifest.json');

            if (! is_file($manifest)) {
                return [];
            }

            $entries = json_decode((string) file_get_contents($manifest), true, flags: JSON_THROW_ON_ERROR);
            $files = [];

            foreach (['resources/css/app.css', 'resources/js/app.js'] as $entry) {
                if (isset($entries[$entry]['file'])) {
                    $files[] = '/build/'.$entries[$entry]['file'];
                }
                foreach ($entries[$entry]['css'] ?? [] as $css) {
                    $files[] = '/build/'.$css;
                }
            }

            return $files;
        } catch (Throwable) {
            return [];
        }
    }

    private function logo(): ?Media
    {
        // The square icon when there is one; the wide lockup otherwise, which
        // the renderer letterboxes rather than squashes.
        $id = (int) (setting('header.logo_icon') ?: setting('header.logo_light') ?: 0);

        return $id > 0 ? Media::query()->find($id) : null;
    }

    private function renderIcon(int $size): string
    {
        $canvas = imagecreatetruecolor($size, $size);
        $brand = $this->rgb(app(ThemeTokens::class)->value('brand-primary', 'light'));
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, ...$brand));

        $logo = $this->logo();
        $source = null;

        if ($logo !== null) {
            try {
                $source = @imagecreatefromstring((string) file_get_contents($logo->getPath()));
            } catch (Throwable) {
                $source = null;
            }
        }

        if ($source !== false && $source !== null) {
            // The maskable safe zone is the central 80 %; the logo sits inside
            // 64 % so a circular mask never clips it.
            $box = (int) round($size * 0.64);
            $w = imagesx($source);
            $h = imagesy($source);
            $scale = min($box / max($w, 1), $box / max($h, 1));
            $dw = max(1, (int) round($w * $scale));
            $dh = max(1, (int) round($h * $scale));
            imagealphablending($canvas, true);
            imagecopyresampled($canvas, $source, (int) (($size - $dw) / 2), (int) (($size - $dh) / 2), 0, 0, $dw, $dh, $w, $h);
            imagedestroy($source);
        }

        ob_start();
        imagepng($canvas, null, 6);
        imagedestroy($canvas);

        return (string) ob_get_clean();
    }

    /** @return array{0: int, 1: int, 2: int} */
    private function rgb(string $hex): array
    {
        $hex = ltrim(trim($hex), '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        if (strlen($hex) !== 6 || ! ctype_xdigit($hex)) {
            return [11, 77, 63];
        }

        return [(int) hexdec(substr($hex, 0, 2)), (int) hexdec(substr($hex, 2, 2)), (int) hexdec(substr($hex, 4, 2))];
    }
}
