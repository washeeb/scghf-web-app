<?php

declare(strict_types=1);

use App\Http\Controllers\PaystackWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
|--------------------------------------------------------------------------
| Payment webhooks
|--------------------------------------------------------------------------
|
| Registered from config rather than hardcoded, so the path can be changed
| without a deploy if it ever needs to be — a webhook URL is effectively
| public, and being able to rotate it is worth the indirection.
|
| CSRF is exempted in bootstrap/app.php: Paystack is a server, it has no
| session and no token, and the endpoint is authenticated by the HMAC
| signature instead. That is a stronger check than CSRF, not a weaker one.
|
| Deliberately NOT rate-limited. Paystack retries on any non-2xx, so throttling
| it into 429s would turn a busy minute into a retry storm — and the endpoint
| already answers 200 to everything, storing rather than trusting.
*/
Route::post(
    (string) config('payments.paystack.webhook_path', '/webhooks/paystack'),
    PaystackWebhookController::class,
)->name('webhooks.paystack');
