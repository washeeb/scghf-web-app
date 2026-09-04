<?php

declare(strict_types=1);

use App\Http\Controllers\DeliveryWebhookController;
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

/*
|--------------------------------------------------------------------------
| Delivery webhooks — bounces, complaints and delivery reports
|--------------------------------------------------------------------------
|
| One route for every provider, distinguished by a path segment, because the
| difference between them is a signature scheme rather than a workflow.
|
| Under `/webhooks/` because that is the prefix bootstrap/app.php exempts from
| CSRF. A provider posts from a server: it holds no session and no token, and
| the endpoint is authenticated by its HMAC signature instead — a stronger check
| than CSRF, which only proves a request came from our own page.
|
| Not rate-limited, for the same reason the Paystack endpoint is not: providers
| retry on any non-2xx, so throttling into 429s turns a busy minute into a retry
| storm. The endpoint answers 200 to everything and stores rather than trusts.
*/
Route::post(
    trim((string) config('communications.webhooks.path_prefix', 'webhooks/delivery'), '/').'/{provider}',
    DeliveryWebhookController::class,
)->name('webhooks.delivery');
