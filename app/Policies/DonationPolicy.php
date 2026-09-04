<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * Donations, their line items, receipts and giving plans.
 *
 * Append-only: a completed donation is never deleted or edited, and a
 * correction is a new row. That is what makes the ledger auditable, and it is
 * the difference between a system a trustee can sign off and one they cannot.
 *
 * Note there is no delete permission to grant. Adding one would be adding the
 * ability to rewrite what the foundation received.
 */
class DonationPolicy extends AppendOnlyPolicy
{
    protected function prefix(): string
    {
        return 'donations';
    }
}
