<?php

declare(strict_types=1);

namespace App\Communications\Contracts;

use App\Communications\SmsResult;
use App\Models\SmsLog;
use App\Models\WhatsappTemplate;

/**
 * What a WhatsApp provider has to be able to do — send one approved
 * template to one number with its parameters in Meta's order, and say
 * what the provider said. Same shape and same reasons as SmsGateway: the
 * whole channel is buildable and testable before the foundation has a
 * number, and the log driver costs and records every message it does
 * not send.
 */
interface WhatsappGateway
{
    /** `log` or `cloud`. */
    public function name(): string;

    /**
     * @param  array<int, string>  $parameters  the values for {{1}}, {{2}} …
     */
    public function send(SmsLog $log, WhatsappTemplate $template, array $parameters): SmsResult;

    /** Whether the provider reports delivery back (the Cloud API does; the log driver cannot). */
    public function reportsDelivery(): bool;
}
