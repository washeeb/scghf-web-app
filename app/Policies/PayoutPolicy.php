<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * Money leaving the foundation.
 *
 * Append-only like the rest of the ledger. The separation-of-duties rule —
 * that whoever requests a payment may not approve it — lives in
 * App\Models\Payout, because it is a fact about the two people involved
 * rather than about one person's permissions.
 */
class PayoutPolicy extends AppendOnlyPolicy
{
    protected function prefix(): string
    {
        return 'payouts';
    }
}
