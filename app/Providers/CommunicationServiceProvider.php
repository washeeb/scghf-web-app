<?php

declare(strict_types=1);

namespace App\Providers;

use App\Communications\ArkeselGateway;
use App\Communications\Contracts\SmsGateway;
use App\Communications\HubtelGateway;
use App\Communications\LogSmsGateway;
use App\Communications\MessageDispatcher;
use App\Communications\MnotifyGateway;
use App\Communications\SendThrottle;
use App\Communications\SmsSegmenter;
use App\Communications\TemplateRenderer;
use App\Communications\TwilioGateway;
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
    /**
     * The driver: Settings → Communications first, `.env` as the default.
     *
     * The keys stay in `.env` — a secret is not a setting — but which
     * provider is live is an operational choice the foundation makes when
     * one goes dark, and it should not need a deploy.
     */
    public static function smsDriver(): string
    {
        try {
            $chosen = (string) setting('communications.sms_driver', '');
        } catch (\Throwable) {
            $chosen = '';
        }

        return $chosen !== '' ? $chosen : (string) config('communications.sms.driver', 'log');
    }

    public function register(): void
    {
        $this->app->singleton(SmsSegmenter::class);
        $this->app->singleton(TemplateRenderer::class);
        $this->app->singleton(SendThrottle::class);

        $this->app->singleton(SmsGateway::class, function ($app): SmsGateway {
            return match ($driver = self::smsDriver()) {
                'log' => $app->make(LogSmsGateway::class),
                'mnotify' => $app->make(MnotifyGateway::class),
                'arkesel' => $app->make(ArkeselGateway::class),
                'hubtel' => $app->make(HubtelGateway::class),
                'twilio' => $app->make(TwilioGateway::class),
                default => throw new RuntimeException(
                    "Unknown SMS driver [{$driver}]. Use 'mnotify', 'arkesel', 'hubtel', 'twilio', "
                    ."or 'log', which costs and records every message and sends none."
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
        $this->assertProviderIsConfigured();
    }

    /**
     * Production refuses to boot with a provider selected and no key for it.
     *
     * Same reasoning as the Paystack guard in PaymentServiceProvider: this
     * configuration cannot send a single message, it is purely a deployment
     * mistake, and the alternative is discovering it one failed receipt at a
     * time. `SMS_DRIVER=log` is left alone — it is a legitimate state that
     * tells the truth about what it did.
     */
    private function assertProviderIsConfigured(): void
    {
        if (! $this->app->isProduction()) {
            return;
        }

        if ((string) config('communications.sms.driver') !== 'mnotify') {
            return;
        }

        if ((string) config('communications.sms.mnotify.api_key', '') === '') {
            throw new RuntimeException(
                'SMS_DRIVER is mnotify but MNOTIFY_API_KEY is empty, so no SMS can be sent. '
                .'Set the key, or set SMS_DRIVER=log, which costs and records every message '
                .'and sends none.'
            );
        }
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
