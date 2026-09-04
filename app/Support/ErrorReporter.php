<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ErrorReport;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Turns an exception into a grouped, scrubbed row somebody can read.
 *
 * Hooked into the framework's exception handling in `bootstrap/app.php`. It
 * does not replace logging — the log file is still the detailed record — it
 * makes faults visible in the admin panel to people who will never open a log
 * file, which on a shared host is everybody at the foundation.
 *
 * ── The three rules ─────────────────────────────────────────────────────────
 *
 * 1. NOTHING FROM THE REQUEST BODY. PCI DSS SAQ-A posture rests on this
 *    application never touching card data, and an error report that grabbed the
 *    POST body would quietly make that untrue. A beneficiary's narrative
 *    arriving in a failed form submission is the same problem wearing different
 *    clothes.
 *
 * 2. ORDINARY TRAFFIC IS NOT AN ERROR. A bot probing for /wp-login.php produces
 *    a NotFoundHttpException; a mistyped password produces an
 *    AuthenticationException. Recording those buries the one fault that is
 *    real under ten thousand that are not.
 *
 * 3. REPORTING NEVER THROWS. If this fails, the original exception is what
 *    matters, and an error reporter that turns a 500 into a different 500 has
 *    made the situation strictly worse.
 */
class ErrorReporter
{
    public function report(Throwable $e): ?ErrorReport
    {
        try {
            if ($this->shouldIgnore($e)) {
                return null;
            }

            $report = ErrorReport::recordOccurrence(
                ErrorReport::fingerprintFor($e),
                [
                    'exception_class' => $e::class,
                    'message' => Str::limit($e->getMessage(), 2000, ''),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $this->applicationTrace($e),
                    'route' => $this->route(),
                    'method' => $this->method(),
                    'severity' => $this->severity($e),
                    'context' => $this->context(),
                    'affected_visitor' => ! app()->runningInConsole(),
                ],
            );

            if (auth()->check()) {
                $report->noteAffectedUser();
            }

            return $report;
        } catch (Throwable $inner) {
            // The original exception is the one that matters. Say this failed
            // in the log and get out of the way.
            Log::warning('Could not record an error report.', ['error' => $inner->getMessage()]);

            return null;
        }
    }

    private function shouldIgnore(Throwable $e): bool
    {
        /** @var array<int, class-string> $ignored */
        $ignored = config('system.errors.ignore', []);

        foreach ($ignored as $class) {
            if ($e instanceof $class) {
                return true;
            }
        }

        return false;
    }

    /**
     * Severity, from where the fault happened rather than from its class.
     *
     * Anything on the payment or webhook path is critical by definition: a
     * broken donation form is not the same kind of problem as a broken gallery
     * page, however similar the stack trace looks.
     */
    private function severity(Throwable $e): string
    {
        $where = $e->getFile().' '.($this->route() ?? '');

        foreach (['Payments', 'webhooks', 'Donation', 'Receipt', 'Order'] as $needle) {
            if (str_contains($where, $needle)) {
                return ErrorReport::SEVERITY_CRITICAL;
            }
        }

        return ErrorReport::SEVERITY_ERROR;
    }

    /**
     * The application's own frames, and only those.
     *
     * A hundred lines of framework internals is not what tells somebody what
     * broke — and it is the same hundred lines on every fault, stored again
     * each time.
     */
    private function applicationTrace(Throwable $e): string
    {
        $base = base_path();
        $lines = [];

        foreach ($e->getTrace() as $frame) {
            $file = $frame['file'] ?? null;

            if ($file === null || str_contains($file, 'vendor'.DIRECTORY_SEPARATOR)) {
                continue;
            }

            $lines[] = sprintf(
                '%s:%s %s%s%s()',
                str_replace($base.DIRECTORY_SEPARATOR, '', $file),
                $frame['line'] ?? '?',
                $frame['class'] ?? '',
                $frame['type'] ?? '',
                $frame['function'] ?? '',
            );

            if (count($lines) >= 20) {
                break;
            }
        }

        return implode("\n", $lines);
    }

    private function route(): ?string
    {
        if (app()->runningInConsole() || ! app()->bound('request')) {
            return null;
        }

        // The route's PATTERN, not the URL. `/donations/{donation}` groups
        // properly and, unlike a real URL, carries no id, no token and no
        // query string.
        return request()->route()?->uri() ?? request()->path();
    }

    private function method(): ?string
    {
        return app()->runningInConsole() ? 'console' : request()->method();
    }

    /**
     * A little context, scrubbed hard.
     *
     * Route parameter NAMES, never their values — `{donation}` is useful for
     * reproducing a fault; the donation's id is one join away from a donor.
     *
     * @return array<string, mixed>
     */
    private function context(): array
    {
        if (app()->runningInConsole()) {
            return ['context' => 'console'];
        }

        return array_filter([
            'route_name' => request()->route()?->getName(),
            'route_parameters' => array_keys(request()->route()?->parameters() ?? []),
            'is_authenticated' => auth()->check(),
            'is_ajax' => request()->ajax(),
        ], fn ($value): bool => $value !== null && $value !== []);
    }
}
