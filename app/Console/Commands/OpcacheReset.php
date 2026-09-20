<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Controllers\OpcacheResetController;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Ask the web workers to empty their OPcache.
 *
 * Run by `activate.sh` after the release symlink moves. See
 * `OpcacheResetController` for why a deploy needs it and why the token is
 * one-time. Exits non-zero when the reset did not happen, so the deploy
 * log says so; the deploy itself carries on, because a site serving the
 * previous release's bytecode for a while is a lesser evil than a rolled
 * back release.
 *
 *     php artisan scghf:opcache-reset
 */
class OpcacheReset extends Command
{
    protected $signature = 'scghf:opcache-reset {--url= : Base URL to call (default: APP_URL)}';

    protected $description = 'Empty the PHP-FPM OPcache in the web workers, through a one-time token';

    public function handle(): int
    {
        $token = Str::random(48);
        Cache::put(OpcacheResetController::PREFIX.$token, true, now()->addMinutes(2));

        $base = rtrim((string) ($this->option('url') ?: config('app.url')), '/');
        $url = $base.'/deploy/opcache-reset';

        try {
            $response = Http::timeout(20)
                ->withUserAgent('SCGHF deploy (opcache reset)')
                ->post($url, ['token' => $token]);
        } catch (Throwable $e) {
            Cache::forget(OpcacheResetController::PREFIX.$token);
            $this->error("Could not reach {$url}: {$e->getMessage()}");

            return self::FAILURE;
        }

        $body = $response->json();

        if ($response->successful() && ($body['reset'] ?? false) === true) {
            $this->info(sprintf('OPcache reset in the web workers (%s, %s scripts were cached).', $body['sapi'] ?? '?', $body['scripts_before'] ?? '?'));

            return self::SUCCESS;
        }

        $this->error(sprintf('OPcache was not reset: HTTP %d %s', $response->status(), $body['reason'] ?? $response->body()));

        return self::FAILURE;
    }
}
