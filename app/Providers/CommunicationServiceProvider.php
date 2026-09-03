<?php

declare(strict_types=1);

namespace App\Providers;

use App\Communications\Contracts\SmsGateway;
use App\Communications\LogSmsGateway;
use App\Communications\MessageDispatcher;
use App\Communications\SendThrottle;
use App\Communications\SmsSegmenter;
use App\Communications\TemplateRenderer;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Wires the communications channel.
 *
 * Its own provider, like payments, because the boot guard below is a deployment
 * safety check and belongs somewhere a reviewer can find it.
 */
class CommunicationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SmsSegmenter::class);
        $this->app->singleton(TemplateRenderer::class);
        $this->app->singleton(SendThrottle::class);

        $this->app->singleton(SmsGateway::class, function ($app): SmsGateway {
            return match ($driver = (string) config('communications.sms.driver', 'log')) {
                'log' => $app->make(LogSmsGateway::class),
                /*
                 * Arkesel, Hubtel and mNotify go here once the Foundation has
                 * chosen one and registered the sender ID. Deliberately not
                 * stubbed: an empty implementation that silently succeeds is
                 * worse than no implementation, because `log` at least tells
                 * the truth about what it did.
                 */
                default => throw new RuntimeException(
                    "Unknown SMS driver [{$driver}]. Only 'log' is implemented; a provider "
                    .'is chosen once the sender ID is registered with the networks.'
                ),
            };
        });

        $this->app->singleton(MessageDispatcher::class, fn ($app): MessageDispatcher => new MessageDispatcher(
            $app->make(SmsGateway::class),
            $app->make(SmsSegmenter::class),
        ));
    }

    public function boot(): void
    {
        $this->assertSenderIdIsPlausible();
    }

    /**
     * A sender ID longer than eleven characters is rejected by the networks.
     *
     * Checked at boot rather than at send time because the failure is silent:
     * the provider accepts the message, the network drops it, and nothing
     * anywhere reports an error. A month of "we sent it, they must not have
     * seen it" is the usual way this is discovered.
     *
     * Only fatal in production. Locally, `log` sends nothing anyway and a
     * refusal to boot over a config value would be noise.
     */
    private function assertSenderIdIsPlausible(): void
    {
        $senderId = (string) config('communications.sms.sender_id', '');
        $max = (int) config('communications.sms.sender_id_max_length', 11);

        if (mb_strlen($senderId) <= $max) {
            return;
        }

        if ($this->app->isProduction()) {
            throw new RuntimeException(
                "SMS_SENDER_ID is {$max}+ characters. Ghanaian networks reject an alphanumeric "
                .'sender ID longer than that, and they reject it silently — every message would '
                .'be accepted by the provider and dropped by the network.'
            );
        }
    }
}
