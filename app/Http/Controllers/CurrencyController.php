<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\CurrencyDisplay;
use App\Support\ExchangeRates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Remembers the visitor's second currency in a cookie and sends them back
 * to the page they were on — a page on this site, checked, because a
 * `return` field is an open redirect if it is not.
 */
class CurrencyController extends Controller
{
    public function set(Request $request): RedirectResponse
    {
        $choice = strtoupper((string) $request->input('currency', 'none'));
        $value = in_array($choice, ExchangeRates::CURRENCIES, true) ? $choice : 'none';

        $return = (string) $request->input('return', '/');
        $host = parse_url($return, PHP_URL_HOST);

        if ($host !== null && $host !== $request->getHost()) {
            $return = '/';
        }

        return redirect()->to($return ?: '/')->withCookie(cookie(
            CurrencyDisplay::COOKIE,
            $value,
            CurrencyDisplay::COOKIE_MINUTES,
            '/',
            null,
            $request->isSecure(),
            false, // read by nothing but the server; still not httpOnly so a future script could read the choice
            false,
            'Lax',
        ));
    }
}
