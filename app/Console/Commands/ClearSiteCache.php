<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Settings;
use App\Support\SiteCache;
use App\Support\ThemeTokens;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Empty everything the public site is served from.
 *
 * `cache:clear` empties the default store and nothing else: not the page
 * cache, which has its own store, and not the in-process settings copy.
 * This is the one command that does all of it, and it is what the deploy
 * script runs after the symlink flips: a new release renders differently,
 * and a page stored by the old one must not be the first thing the new
 * one serves.
 */
class ClearSiteCache extends Command
{
    protected $signature = 'scghf:cache-clear';

    protected $description = 'Empty the page cache and start a new fragment-cache generation.';

    public function handle(): int
    {
        try {
            Cache::store((string) config('performance.page_cache.store', 'pages'))->flush();
            $this->info('Page cache emptied.');
        } catch (Throwable $e) {
            $this->warn('Page cache could not be emptied: '.$e->getMessage());
        }

        SiteCache::bump();
        app(Settings::class)->flush();
        ThemeTokens::flush();

        $this->info('Fragment cache generation is now '.SiteCache::generation().'.');

        return self::SUCCESS;
    }
}
