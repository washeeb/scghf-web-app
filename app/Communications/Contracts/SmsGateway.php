<?php

declare(strict_types=1);

namespace App\Communications\Contracts;

use App\Communications\SmsResult;
use App\Models\SmsLog;

/**
 * What an SMS provider has to be able to do.
 *
 * The interface exists for the same reason PaymentGateway does: the whole
 * module has to be buildable and testable before an account exists, which is
 * the situation this project is in. `LogSmsGateway` writes a complete, costed,
 * segmented row and sends nothing, so the Foundation can see what a month of
 * SMS would cost before signing anything.
 *
 * Ghanaian providers (Arkesel, Hubtel, mNotify) each have their own payload
 * shape and their own delivery-report format. Keeping that behind this
 * interface is what stops provider knowledge leaking into the donation and
 * volunteer code that triggers the messages.
 */
interface SmsGateway
{
    /** `log`, `arkesel`, `hubtel`, `mnotify`. */
    public function name(): string;

    /**
     * Hand one message to the provider.
     *
     * The log row already exists and is already costed — the gateway's job is
     * to send it and report back what the provider said, not to decide what the
     * message is.
     */
    public function send(SmsLog $log): SmsResult;

    /**
     * Whether this gateway can tell us a message actually arrived.
     *
     * Matters in Ghana specifically: an unregistered sender ID is accepted by
     * the provider and dropped by the network with no error. Without delivery
     * reports there is no way to detect that from our side, so a gateway that
     * returns false here is one whose `sent` must never be read as `delivered`.
     */
    public function supportsDeliveryReports(): bool;
}
