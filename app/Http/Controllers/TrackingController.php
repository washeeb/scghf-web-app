<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\EmailLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * The open pixel and the click redirect.
 *
 * Both answer whatever happens: a pixel that 404s is a broken image in a
 * newsletter, and a click that fails is a reader who cannot reach the
 * appeal. Counting is best-effort; the destination is not.
 */
class TrackingController extends Controller
{
    private const GIF = "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xff\xff\xff\x21\xf9\x04\x01\x00\x00\x00\x00\x2c\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02\x44\x01\x00\x3b";

    public function open(string $log): Response
    {
        EmailLog::query()->where('ulid', $log)->update([
            'opened_at' => DB::raw('COALESCE(opened_at, NOW())'),
            'open_count' => DB::raw('open_count + 1'),
        ]);

        return response(self::GIF, 200, [
            'Content-Type' => 'image/gif',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    public function click(Request $request, string $log): RedirectResponse
    {
        $to = (string) $request->query('to', '');

        // Only a signed link may redirect off-site: without the check this
        // would be an open redirect on the foundation's domain.
        abort_unless($request->hasValidSignature() && preg_match('#^https?://#i', $to) === 1, 404);

        EmailLog::query()->where('ulid', $log)->update([
            'clicked_at' => DB::raw('COALESCE(clicked_at, NOW())'),
            'click_count' => DB::raw('click_count + 1'),
        ]);

        return redirect()->away($to);
    }
}
