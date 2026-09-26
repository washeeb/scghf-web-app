<?php

declare(strict_types=1);

use App\Http\Controllers\OpcacheResetController;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| The deploy's OPcache reset
|--------------------------------------------------------------------------
|
| PHP-FPM keeps bytecode across deploys. The deploy mints a one-time token
| into the shared cache and POSTs it; the route pulls it and resets. Under
| the test runner there is no OPcache to reset, which is itself one of the
| honest answers the route can give.
*/

it('refuses without a token, with a wrong token, and a second time with the same token', function () {
    $this->post('/deploy/opcache-reset')->assertStatus(403);
    $this->post('/deploy/opcache-reset', ['token' => 'nope'])->assertStatus(403);

    Cache::put(OpcacheResetController::PREFIX.'once', true, 60);

    $first = $this->post('/deploy/opcache-reset', ['token' => 'once']);
    $first->assertOk();
    expect($first->json('reset'))->toBeIn([true, false]);

    // Pulled on use: the same token is gone.
    $this->post('/deploy/opcache-reset', ['token' => 'once'])->assertStatus(403);
});

it('is rate-limited, because a flush is a nuisance somebody could repeat', function () {
    foreach (range(1, 6) as $i) {
        $this->post('/deploy/opcache-reset', ['token' => 'x'.$i])->assertStatus(403);
    }

    $this->post('/deploy/opcache-reset', ['token' => 'x7'])->assertStatus(429);
});

it('mints a token, calls the site, and reports what the workers said', function () {
    Http::fake(function ($request) {
        $token = $request['token'] ?? '';

        // Behave like the real route: the token must be in the cache, once.
        return Cache::pull(OpcacheResetController::PREFIX.$token)
            ? Http::response(['reset' => true, 'sapi' => 'fpm-fcgi', 'scripts_before' => 3808])
            : Http::response(['reset' => false, 'reason' => 'no such token'], 403);
    });

    $this->artisan('scghf:opcache-reset', ['--url' => 'https://example.test'])
        ->expectsOutputToContain('OPcache reset in the web workers (fpm-fcgi, 3808 scripts were cached, attempt 1)')
        ->assertSuccessful();

    Http::assertSent(fn ($request) => $request->url() === 'https://example.test/deploy/opcache-reset' && strlen((string) $request['token']) === 48);
});

it('exits non-zero when the site could not be reached or refused, and leaves no token behind', function () {
    Http::fake(['*' => Http::response(['reset' => false, 'reason' => 'no such token'], 403)]);

    $this->artisan('scghf:opcache-reset', ['--url' => 'https://example.test'])
        ->expectsOutputToContain('OPcache was not reset: HTTP 403 no such token')
        ->assertFailed();
});

it('keeps trying while the workers still answer with the previous release, which has no such route', function () {
    $calls = 0;
    Http::fake(function ($request) use (&$calls) {
        $calls++;

        // The old code: the CMS catch-all, 405 to a POST. The new code arrives on the third try.
        return $calls < 3
            ? Http::response('<html>Method Not Allowed</html>', 405)
            : Http::response(['reset' => true, 'sapi' => 'fpm-fcgi', 'scripts_before' => 12]);
    });

    $this->artisan('scghf:opcache-reset', ['--url' => 'https://example.test', '--pause' => 0])
        ->expectsOutputToContain('attempt 3')
        ->assertSuccessful();

    expect($calls)->toBe(3);
});
