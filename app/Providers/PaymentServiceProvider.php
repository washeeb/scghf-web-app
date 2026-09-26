<?php

declare(strict_types=1);

namespace App\Providers;

use App\Payments\Contracts\PaymentGateway;
use App\Payments\FakeGateway;
use App\Payments\FeeCalculator;
use App\Payments\PayloadScrubber;
use App\Payments\PaymentManager;
use App\Payments\PaystackService;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Wires the payment driver.
 *
 * Its own provider rather than lines in AppServiceProvider, because the boot
 * guard below is a deployment safety check and deserves to be somewhere
 * obvious. A reviewer asking "can this site take pretend payments in
 * production?" should find the answer in one file.
 */
class PaymentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PayloadScrubber::class);

        $this->app->singleton(FeeCalculator::class, fn (): FeeCalculator => FeeCalculator::fromConfig());

        $this->app->singleton(PaymentGateway::class, function ($app): PaymentGateway {
            return match ($driver = (string) config('payments.driver', 'fake')) {
                'paystack' => $app->make(PaystackService::class),
                'fake' => $app->make(FakeGateway::class),
                default => throw new RuntimeException(
                    "Unknown payment driver [{$driver}]. Use 'paystack' or 'fake'."
                ),
            };
        });

        $this->app->singleton(PaymentManager::class, fn ($app): PaymentManager => new PaymentManager(
            $app->make(PaymentGateway::class),
            $app->make(PayloadScrubber::class),
        ));
    }

    public function boot(): void
    {
        $this->assertProductionIsNotFaking();
    }

    /**
     * Production refuses to boot on the fake driver.
     *
     * A live site quietly accepting pretend payments is worse than a live site
     * that will not start: the donor believes they have given, the foundation
     * believes it has received, and nobody finds out until a reconciliation
     * that may be weeks away.
     *
     * Deliberately fatal rather than a logged warning. A warning in a log
     * nobody is watching is the same as no warning at all — and this is exactly
     * the mistake a rushed deploy makes.
     */
    private function assertProductionIsNotFaking(): void
    {
        if (! $this->app->isProduction()) {
            return;
        }

        if ((string) config('payments.driver') !== 'paystack') {
            throw new RuntimeException(
                'PAYMENT_DRIVER is not "paystack" in production. The site will not start with a '
                .'fake payment gateway live — set the real driver and the Paystack keys.'
            );
        }

        $secret = (string) config('payments.paystack.secret_key');

        if ($secret === '' || str_starts_with($secret, 'sk_test_')) {
            throw new RuntimeException(
                'Production is configured with a missing or TEST Paystack secret key. '
                .'Real donations would fail or be taken against a test account.'
            );
        }
    }
}
