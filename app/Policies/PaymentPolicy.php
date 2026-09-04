<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * Gateway transactions, webhook events and refunds.
 *
 * The boundary with Paystack. Every row here is evidence of what the gateway
 * said, and evidence somebody can edit is not evidence.
 *
 * `payments.view_keys` is deliberately NOT reachable through this policy —
 * reading the live secret key lets somebody move money outside the
 * application entirely, where no audit trail reaches, and it stays with Super
 * Admin alone.
 */
class PaymentPolicy extends AppendOnlyPolicy
{
    protected function prefix(): string
    {
        return 'payments';
    }
}
