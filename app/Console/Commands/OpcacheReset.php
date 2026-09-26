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
    protected $signature = 'scghf:opcache-reset {--url= : Base URL to call (default: APP_URL)} {--pause=15 : Seconds between attempts while the previous release still answers}';

    protected $description = 'Empty the PHP-FPM OPcache in the web workers, through a one-time token';

    /** Attempts while the workers still answer with the previous release. */
    public const ATTEMPTS = 8;

    public function handle(): int
    {
        $base = rtrim((string) ($this->option('url') ?: config('app.url')), '/');
        $url = $base.'/deploy/opcache-reset';
        $pause = max(0, (int) $this->option('pause'));

        /*
         * Right after the flip the workers may still be running the previous
         * release — the very condition this command exists to end — and that
         * release has no such route: the CMS catch-all answers 405 to a POST,
         * or 404. Those answers come from the old code, so we wait for the
         * workers' own revalidation to bring the new route into reach and try
         * again, for a couple of minutes. A 403 is the new route refusing a
         * token, and is final.
         */
        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            $token = Str::random(48);
            Cache::put(OpcacheResetController::PREFIX.$token, true, now()->addMinutes(2));

            try {
                $response = Http::timeout(40)
                    ->withUserAgent('SCGHF deploy (opcache reset)')
                    ->acceptJson()
                    ->post($url, ['token' => $token]);
            } catch (Throwable $e) {
                Cache::forget(OpcacheResetController::PREFIX.$token);

                // A timeout right after the flip is the workers being slow to
                // load the new release, not the site being down: try again.
                if ($attempt === self::ATTEMPTS) {
                    $this->error("Could not reach {$url}: {$e->getMessage()}");

                    return self::FAILURE;
                }

                $this->line(sprintf('  no answer yet (%s); waiting %ds (attempt %d of %d)', $e->getMessage(), $pause, $attempt, self::ATTEMPTS));
                sleep($pause);

                continue;
            }

            $body = $response->json();

            if ($response->successful() && ($body['reset'] ?? false) === true) {
                $this->info(sprintf('OPcache reset in the web workers (%s, %s scripts were cached, attempt %d).', $body['sapi'] ?? '?', $body['scripts_before'] ?? '?', $attempt));

                return self::SUCCESS;
            }

            Cache::forget(OpcacheResetController::PREFIX.$token);

            if (! in_array($response->status(), [404, 405], true) || $attempt === self::ATTEMPTS) {
                $this->error(sprintf('OPcache was not reset: HTTP %d %s', $response->status(), is_array($body) ? ($body['reason'] ?? json_encode($body)) : 'not a JSON answer (the previous release is still answering)'));

                return self::FAILURE;
            }

            $this->line(sprintf('  workers still answer with the previous release (HTTP %d); waiting %ds (attempt %d of %d)', $response->status(), $pause, $attempt, self::ATTEMPTS));
            sleep($pause);
        }

        return self::FAILURE;
    }
}
