<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\PageMeta;
use App\Support\Pwa;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The manifest, the icons, the worker and the offline page.
 *
 * The worker is served from `/sw.js` at the root so its scope is the whole
 * site; a worker under `/build/` could only control `/build/`. It is served
 * with the flag's state baked in, so switching the flag off makes the next
 * fetch of the script one that unregisters itself.
 */
class PwaController extends Controller
{
    public function __construct(private readonly Pwa $pwa) {}

    public function manifest(): JsonResponse
    {
        $this->assertEnabled();

        return response()->json($this->pwa->manifest(), 200, ['Content-Type' => 'application/manifest+json'], JSON_UNESCAPED_SLASHES)
            ->setCache(['public' => true, 'max_age' => 3600]);
    }

    public function serviceWorker(): Response
    {
        // Served whether the flag is on or off: an installed worker must be
        // able to fetch the version that tells it to go away.
        return response($this->pwa->serviceWorker(), 200, [
            'Content-Type' => 'application/javascript; charset=UTF-8',
            'Cache-Control' => 'no-cache',
            'Service-Worker-Allowed' => '/',
        ]);
    }

    public function icon(int $size): Response
    {
        $this->assertEnabled();

        if (! in_array($size, Pwa::SIZES, true)) {
            throw new NotFoundHttpException;
        }

        return response($this->pwa->icon($size), 200, ['Content-Type' => 'image/png'])
            ->setCache(['public' => true, 'max_age' => 31536000, 'immutable' => true]);
    }

    public function offline(): View
    {
        return view('offline', [
            'meta' => PageMeta::site(__('You are offline'), noindex: true),
            'momoName' => setting('banking.momo_name'),
            'momoNumber' => setting('banking.momo_number'),
            'bankName' => setting('banking.bank_name'),
            'accountName' => setting('banking.account_name'),
            'accountNumber' => setting('banking.account_number'),
            'phone' => setting('contact.phone_primary'),
        ]);
    }

    private function assertEnabled(): void
    {
        if (! $this->pwa->enabled()) {
            throw new NotFoundHttpException;
        }
    }
}
