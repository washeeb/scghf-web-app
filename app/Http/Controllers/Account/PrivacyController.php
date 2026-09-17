<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Privacy\AccountDataExporter;
use App\Privacy\AccountEraser;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The account holder's rights over their data: a copy, and an ending.
 *
 * Both need the password. A copy of everything the foundation holds is
 * exactly what somebody who finds a laptop open would want; deleting the
 * account is what they would do to be spiteful.
 */
class PrivacyController extends Controller
{
    public function show(Request $request): View
    {
        return view('account.privacy', ['user' => $request->user()]);
    }

    public function export(Request $request, AccountDataExporter $exporter): StreamedResponse
    {
        $request->validate(['current_password' => ['required', 'current_password']]);

        $user = $request->user();
        $data = $exporter->export($user);

        app(AuditLogger::class)->record('privacy.exported', 'The account holder downloaded a copy of their data.', subject: $user, causer: $user);

        $filename = 'my-data-'.now()->format('Y-m-d').'.json';

        return response()->streamDownload(
            fn () => print json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $filename,
            ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store'],
        );
    }

    public function destroy(Request $request, AccountEraser $eraser): RedirectResponse
    {
        $request->validate([
            'current_password' => ['required', 'current_password'],
            'confirm' => ['accepted'],
        ]);

        $user = $request->user();

        auth()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $eraser->erase($user);

        return redirect('/')->with('status', __('Your account has been deleted. Records of any donations are kept, without your name, for as long as the tax rules require. Thank you for everything you gave.'));
    }
}
