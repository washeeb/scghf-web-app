<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * Shop orders, their items, status history, invoices and download tokens.
 *
 * A paid order is a financial record and is never deleted. Fulfilment moves it
 * forward; nothing moves it back.
 */
class OrderPolicy extends AppendOnlyPolicy
{
    protected function prefix(): string
    {
        return 'orders';
    }
}
