<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Where a browser reports a Content Security Policy violation.
 *
 * Logged at warning, throttled hard: a report is a browser saying "this
 * page tried to run something the policy forbids", which is either a bug
 * in the policy or an injection attempt, and both deserve a line in the
 * log. Nothing else — no storage, no reply, no reading past the fields
 * that matter, because anybody on the internet can post here.
 */
class CspReportController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $body = json_decode((string) $request->getContent(), true);
        $report = is_array($body) ? ($body['csp-report'] ?? $body) : [];

        if (is_array($report) && $report !== []) {
            Log::warning('CSP violation reported.', [
                'document' => (string) ($report['document-uri'] ?? ''),
                'directive' => (string) ($report['violated-directive'] ?? $report['effective-directive'] ?? ''),
                'blocked' => mb_substr((string) ($report['blocked-uri'] ?? ''), 0, 200),
                'sample' => mb_substr((string) ($report['script-sample'] ?? ''), 0, 100),
                'ip' => $request->ip(),
            ]);
        }

        return response('', 204);
    }
}
