<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

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

    /**
     * The read permission is `payments.view_transactions`, not `payments.view`
     * — named for what it shows, because "view payments" reads as if it might
     * include the keys. Without this override the base policy would look for
     * `payments.view`, find nothing, and hide the Refunds and Webhook screens
     * from everybody but Super Admin.
     */
    public function viewAny(User $user): bool
    {
        return $this->permits($user, 'view_transactions');
    }

    public function view(User $user, Model $model): bool
    {
        return $this->permits($user, 'view_transactions');
    }
}
