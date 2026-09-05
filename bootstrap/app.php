<?php

use App\Http\Middleware\CountVisit;
use App\Http\Middleware\HandleRedirects;
use App\Support\ErrorReporter;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * Paystack is a server posting to us. It holds no session and no CSRF
         * token, so the check would reject every webhook.
         *
         * The endpoint is not unprotected: it is authenticated by an
         * HMAC-SHA512 signature over the raw body, compared with hash_equals().
         * That is a stronger guarantee than CSRF, which only proves the request
         * came from our own page.
         *
         * A PATTERN, not a config read: this closure runs before the config
         * repository is bound, so `config()` here throws "Target class [config]
         * does not exist". Matching the whole `webhooks/` prefix also means
         * rotating PAYSTACK_WEBHOOK_PATH does not silently start rejecting
         * every delivery.
         *
         * The constraint that comes with it: a webhook path must live under
         * `/webhooks/`.
         */
        $middleware->validateCsrfTokens(except: [
            'webhooks/*',
        ]);

        /*
         * Counts page views, and nothing about the people who cause them.
         *
         * Appended to the web group so it runs after the session middleware —
         * it needs the session that already exists, and creates nothing of its
         * own. It counts after the response has been produced and swallows
         * every failure: statistics are never worth a millisecond of a donor's
         * time on a 3G connection, and certainly never worth an error page.
         */
        /*
         * Redirects, and the 404 log.
         *
         * `HandleRedirects` runs on the way OUT and only when the response is
         * already a 404, so the redirect table is never queried for a request
         * that resolved. Appended after `CountVisit` for no reason beyond
         * reading order — neither depends on the other.
         */
        $middleware->appendToGroup('web', [
            CountVisit::class,
            HandleRedirects::class,
        ]);

        /*
         * Where an already-signed-in visitor goes if they open /login.
         *
         * Laravel's default is `/`, which is the home page — so somebody who is
         * signed in and clicks a stale "Sign in" link lands on the front page
         * with no explanation, and reasonably concludes the click did nothing.
         * Their account is the answer to what they were trying to reach.
         */
        $middleware->redirectUsersTo('/account');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
         * Record faults where the foundation's staff can see them.
         *
         * This does not replace logging — the log file is still the detailed
         * record. It puts faults in the admin panel, for people who will never
         * open a log file over SSH, which on this host is everybody at the
         * foundation.
         *
         * `reportable` runs alongside the normal handler rather than instead of
         * it: the closure returns nothing, so Laravel still logs as usual.
         *
         * App\Support\ErrorReporter swallows its own failures. An error
         * reporter that turns a 500 into a different 500 has made the situation
         * strictly worse.
         */
        $exceptions->reportable(function (Throwable $e): void {
            app(ErrorReporter::class)->report($e);
        });
    })->create();
