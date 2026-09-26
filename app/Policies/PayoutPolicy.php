<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Payout;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Money leaving the foundation.
 *
 * Append-only like the rest of the ledger. The separation-of-duties rule —
 * that whoever requests a payment may not approve it — lives in
 * App\Models\Payout, because it is a fact about the two people involved
 * rather than about one person's permissions.
 *
 * The permissions are the three steps (`request`, `approve`, `mark_paid`)
 * plus `view`; there is no `payouts.manage` on purpose, so `create` and
 * `update` are mapped here: raising one is requesting, and the only edits
 * are a draft's details or a step in the workflow.
 */
class PayoutPolicy extends AppendOnlyPolicy
{
    protected function prefix(): string
    {
        return 'payouts';
    }

    public function create(User $user): bool
    {
        return $user->can('payouts.request');
    }

    public function update(User $user, Model $model): bool
    {
        if (! $model instanceof Payout) {
            return false;
        }

        // A draft is the requester's to change; after that only the workflow
        // moves it, and each step has its own permission.
        return match ($model->status) {
            Payout::STATUS_DRAFT => $user->can('payouts.request'),
            Payout::STATUS_PENDING => $user->can('payouts.approve'),
            Payout::STATUS_APPROVED => $user->can('payouts.mark_paid'),
            default => false,
        };
    }
}
