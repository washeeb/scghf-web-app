<?php

declare(strict_types=1);

namespace App\Communications;

use App\Communications\Contracts\WhatsappGateway;
use App\Models\SmsLog;
use App\Models\WhatsappTemplate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The default WhatsApp gateway: records and costs the message, sends
 * nothing. `WHATSAPP_DRIVER=log` until Meta has verified the business and
 * approved the templates — the same idea as LogSmsGateway, and for the
 * same reason: a month of "would have sent" is a real estimate of what
 * the channel will cost per conversation.
 */
class LogWhatsappGateway implements WhatsappGateway
{
    public function name(): string
    {
        return 'log';
    }

    public function send(SmsLog $log, WhatsappTemplate $template, array $parameters): SmsResult
    {
        Log::channel(config('logging.default'))->info('WhatsApp (not sent — driver is `log`)', [
            'to' => $log->to_number,
            'template' => $template->key,
            'meta_name' => $template->meta_name,
            'language' => $template->language,
            'parameters' => $parameters,
            'estimated_cost' => (string) $log->estimatedCost(),
            'body' => $log->body,
        ]);

        return SmsResult::accepted(
            providerMessageId: 'wa_log_'.Str::lower(Str::ulid()->toBase32()),
            providerStatus: 'logged',
            raw: ['driver' => 'log'],
        );
    }

    public function reportsDelivery(): bool
    {
        return false;
    }
}
