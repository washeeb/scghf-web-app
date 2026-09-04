<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * Stock movements.
 *
 * An append-only ledger, not a mutable counter — current stock is the sum of
 * its rows. Deleting one would silently change a stock level with nothing
 * recording that it had changed.
 */
class InventoryPolicy extends AppendOnlyPolicy
{
    protected function prefix(): string
    {
        return 'inventory';
    }
}
