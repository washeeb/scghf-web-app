<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Donation;
use App\Support\SiteCache;
use Illuminate\Database\Eloquent\Model;

/**
 * Any change to something the public site shows starts a new cache
 * generation — menus, pages, the announcement, every listed content type,
 * and the pieces of transactional data that public pages display (an
 * appeal's raised total, a product's stock, an event's places left).
 *
 * Registered for the list in AppServiceProvider. Two things are not on it,
 * deliberately: visitor statistics, which change on every request, and
 * logs. A donation bumps only when its status changes: the donor wall and
 * the appeal total move on completion, not on every pending row a bot
 * creates by opening the form.
 */
class SiteCacheObserver
{
    public function saved(Model $model): void
    {
        if ($model instanceof Donation && ! ($model->wasChanged('status') && $model->status->countsTowardsTotals())) {
            return;
        }

        SiteCache::bump();
    }

    public function deleted(Model $model): void
    {
        SiteCache::bump();
    }

    public function restored(Model $model): void
    {
        SiteCache::bump();
    }
}
