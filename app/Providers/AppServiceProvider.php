<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\ContrastChecker;
use App\Support\RetentionRunner;
use App\Support\Settings;
use App\Support\TaxDeductibility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Singletons: Settings holds the whole table in memory for the request,
        // so a second instance would defeat the point.
        $this->app->singleton(Settings::class);
        $this->app->singleton(ContrastChecker::class);
        $this->app->singleton(TaxDeductibility::class);

        // The retention runner holds the registry of models subject to a
        // retention class. Modules register into it as they land, so it needs
        // no knowledge of models that do not exist yet.
        $this->app->singleton(RetentionRunner::class);
    }

    public function boot(): void
    {
        /*
         * Fail loudly in development, quietly in production.
         *
         * strictMode turns lazy loading, silently-discarded attributes and
         * accessing a missing attribute into exceptions. Those are exactly the
         * bugs that hide until a donation page is under load, so they should be
         * impossible to miss locally — but must not take a live donation form
         * down over a template referencing a stale field.
         */
        Model::shouldBeStrict(! $this->app->isProduction());

        // Money and donation totals are only ever assigned as Money or as
        // integers; unguarded mass assignment on financial models is how a
        // crafted request sets an amount it should not.
        Model::unguard(false);

        // Ghana is UTC+0 with no DST, so this changes no arithmetic — but being
        // explicit means "today" in a report is unambiguous.
        Date::use(Carbon::class);
    }
}
