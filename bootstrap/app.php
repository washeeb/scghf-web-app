<?php

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
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
