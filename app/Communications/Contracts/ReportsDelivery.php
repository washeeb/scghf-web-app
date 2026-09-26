<?php

declare(strict_types=1);

namespace App\Communications\Contracts;

/**
 * A gateway that can be asked whether a message actually arrived.
 *
 * Separate from SmsGateway because it is genuinely optional: `log` cannot
 * answer, and some providers charge for it. Anything that polls for reports
 * checks for this interface rather than for a particular provider.
 *
 * This is the interface that makes a silently blocked sender ID detectable. An
 * unregistered alphanumeric sender is accepted by the provider and dropped by
 * the Ghanaian networks with no error returned anywhere — so the only evidence
 * that it is happening is a run of messages that were accepted and never
 * delivered.
 */
interface ReportsDelivery
{
    /**
     * What the network said about a message.
     *
     * Null means "still do not know" — no report yet, a request that failed, or
     * a response shape nothing recognises. Null must never be read as
     * delivered; the log stays `sent`, which is the truthful answer.
     *
     * @return array{status: string, detail: string|null}|null
     */
    public function deliveryReport(string $providerMessageId): ?array;
}
