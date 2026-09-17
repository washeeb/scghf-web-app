<?php

declare(strict_types=1);

namespace App\Observers;

use App\Http\Controllers\SitemapController;
use Illuminate\Database\Eloquent\Model;

/**
 * "Auto-regenerated on publish": the sitemap is a cached query, so
 * regenerating it is forgetting the cache. Registered for every model a
 * sitemap lists. Google retired its sitemap ping endpoint in 2023 and
 * Bing's is IndexNow (a key file and a POST per URL); neither is
 * pretended here — a fresh sitemap with a correct lastmod is what the
 * crawlers actually read.
 */
class SitemapObserver
{
    public function saved(Model $model): void
    {
        SitemapController::forget();
    }

    public function deleted(Model $model): void
    {
        SitemapController::forget();
    }
}
